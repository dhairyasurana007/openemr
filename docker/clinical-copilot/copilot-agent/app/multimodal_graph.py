"""LangGraph multi-agent graph: supervisor + intake_extractor, evidence_retriever, answer_composer."""

from __future__ import annotations

import json
import logging
import time
from typing import Any, TypedDict

_LOG = logging.getLogger("clinical_copilot.multimodal_graph")


# ---------------------------------------------------------------------------
# State
# ---------------------------------------------------------------------------

class CopilotState(TypedDict):
    messages: list
    patient_id: str | None
    extracted_facts: dict | None
    guideline_evidence: list[dict]
    routing_log: list[dict]
    final_answer: str | None
    citations: list[dict]
    token_usage: dict
    _next_node: str


# ---------------------------------------------------------------------------
# LLM helpers
# ---------------------------------------------------------------------------

def _strip_fences(raw: str) -> str:
    raw = raw.strip()
    if raw.startswith("```"):
        lines = raw.splitlines()
        inner = lines[1:] if len(lines) > 1 else lines
        if inner and inner[-1].strip() == "```":
            inner = inner[:-1]
        return "\n".join(inner).strip()
    return raw


def _llm_json(llm: Any, system: str, user: str) -> tuple[dict, dict]:
    """Invoke LLM and parse a JSON response. Returns (parsed, token_usage)."""
    from langchain_core.messages import HumanMessage, SystemMessage

    response = llm.invoke([SystemMessage(content=system), HumanMessage(content=user)])
    parsed = json.loads(_strip_fences(response.content))

    usage: dict = {}
    if hasattr(response, "response_metadata"):
        meta = response.response_metadata or {}
        usage = meta.get("token_usage") or meta.get("usage") or {}

    return parsed, {
        "prompt_tokens": int(usage.get("prompt_tokens", 0)),
        "completion_tokens": int(usage.get("completion_tokens", 0)),
        "total_tokens": int(usage.get("total_tokens", 0)),
    }


def _merge_usage(existing: dict, delta: dict) -> dict:
    out = dict(existing)
    for k in ("prompt_tokens", "completion_tokens", "total_tokens"):
        out[k] = out.get(k, 0) + delta.get(k, 0)
    return out


def _last_message_text(state: CopilotState) -> str:
    msgs = state.get("messages") or []
    if not msgs:
        return ""
    last = msgs[-1]
    if isinstance(last, dict):
        return str(last.get("content", ""))
    if hasattr(last, "content"):
        return str(last.content)
    return str(last)


# ---------------------------------------------------------------------------
# System prompts
# ---------------------------------------------------------------------------

_SUPERVISOR_SYSTEM = """\
You are a clinical co-pilot routing supervisor. Decide which worker to run next given the current state.

Workers:
  intake_extractor  — synthesise a summary from extracted_facts (use when extracted_facts present and no intake summary logged yet)
  evidence_retriever — retrieve clinical guideline evidence (use when query needs guidelines and none yet retrieved)
  answer            — compose the final answer (use when enough context is available)

Respond ONLY with valid JSON on a single line: {"decision": "<worker>", "reason": "<one sentence>"}"""

_INTAKE_SYSTEM = """\
You are a clinical data synthesizer. Summarise the extracted document facts concisely.
Cite each clinical claim back to the source document.
Respond ONLY with valid JSON: {"summary": "<text>", "citations": [{"source_type":"<str>","source_id":"<str>","page_or_section":"<str>","field_or_chunk_id":"<str>","quote_or_value":"<str>"}]}"""

_ANSWER_SYSTEM = """\
You are a clinical co-pilot answer composer. Using the provided context, write a clear, grounded answer.
Every clinical claim must be supported by a specific citation from the context.
If context is insufficient, say so explicitly rather than speculating.
Respond ONLY with valid JSON: {"reply": "<answer>", "citations": [{"source_type":"<str>","source_id":"<str>","page_or_section":"<str>","field_or_chunk_id":"<str>","quote_or_value":"<str>"}]}"""


# ---------------------------------------------------------------------------
# Node factories
# ---------------------------------------------------------------------------

_MAX_ROUTING_STEPS = 6


def _make_supervisor(llm: Any):
    def supervisor(state: CopilotState) -> dict:
        # Guard against infinite loops.
        if len(state.get("routing_log") or []) >= _MAX_ROUTING_STEPS:
            entry = {
                "node": "supervisor",
                "decision": "answer",
                "reason": "maximum routing steps reached",
                "timestamp_ms": int(time.time() * 1000),
            }
            return {
                "routing_log": list(state.get("routing_log") or []) + [entry],
                "_next_node": "answer_composer",
            }

        has_extracted = state.get("extracted_facts") is not None
        intake_done = any(
            e.get("node") == "intake_extractor"
            for e in (state.get("routing_log") or [])
        )
        evidence_done = bool(state.get("guideline_evidence"))

        user_ctx = (
            f"Query: {_last_message_text(state)[:500]}\n"
            f"Has extracted facts: {has_extracted}\n"
            f"Intake summary already done: {intake_done}\n"
            f"Guideline evidence already retrieved: {evidence_done}"
        )

        try:
            parsed, usage = _llm_json(llm, _SUPERVISOR_SYSTEM, user_ctx)
            decision = str(parsed.get("decision", "answer")).strip()
            reason = str(parsed.get("reason", "")).strip()
        except Exception:
            _LOG.exception("supervisor_llm_failed — falling back to answer")
            decision, reason, usage = "answer", "supervisor error — defaulting to answer", {}

        _NODE_MAP = {
            "intake_extractor": "intake_extractor",
            "evidence_retriever": "evidence_retriever",
            "answer": "answer_composer",
        }
        next_node = _NODE_MAP.get(decision, "answer_composer")

        entry = {
            "node": "supervisor",
            "decision": decision,
            "reason": reason,
            "timestamp_ms": int(time.time() * 1000),
        }
        return {
            "routing_log": list(state.get("routing_log") or []) + [entry],
            "_next_node": next_node,
            "token_usage": _merge_usage(state.get("token_usage") or {}, usage),
        }

    return supervisor


def _make_intake_extractor(llm: Any):
    def intake_extractor(state: CopilotState) -> dict:
        facts = state.get("extracted_facts") or {}
        user_prompt = f"Extracted document facts:\n{json.dumps(facts, indent=2)[:3000]}"

        try:
            parsed, usage = _llm_json(llm, _INTAKE_SYSTEM, user_prompt)
            summary = str(parsed.get("summary", ""))
            new_citations = list(parsed.get("citations") or [])
        except Exception:
            _LOG.exception("intake_extractor_llm_failed")
            summary = "Could not synthesize an extraction summary."
            new_citations, usage = [], {}

        entry = {
            "node": "intake_extractor",
            "decision": "summarized",
            "reason": "synthesized structured summary from extracted facts",
            "timestamp_ms": int(time.time() * 1000),
        }

        updated_msgs = list(state.get("messages") or [])
        updated_msgs.append({"role": "assistant", "content": f"[Intake summary]\n{summary}"})

        return {
            "messages": updated_msgs,
            "citations": list(state.get("citations") or []) + new_citations,
            "routing_log": list(state.get("routing_log") or []) + [entry],
            "token_usage": _merge_usage(state.get("token_usage") or {}, usage),
        }

    return intake_extractor


def _make_evidence_retriever(rag_retriever: Any):
    def evidence_retriever(state: CopilotState) -> dict:
        query = _last_message_text(state)
        snippets: list[dict] = []
        if rag_retriever is not None and query:
            try:
                snippets = rag_retriever.retrieve(query, top_k=5)
            except Exception:
                _LOG.exception("evidence_retriever_rag_failed")

        guideline_citations = [
            {
                "source_type": "guideline",
                "source_id": s.get("source", ""),
                "page_or_section": f"chunk_{s.get('chunk_id', 0)}",
                "field_or_chunk_id": str(s.get("chunk_id", 0)),
                "quote_or_value": s.get("text", "")[:200],
            }
            for s in snippets
        ]

        entry = {
            "node": "evidence_retriever",
            "decision": f"retrieved {len(snippets)} snippet(s)",
            "reason": "retrieved clinical guideline evidence for query",
            "timestamp_ms": int(time.time() * 1000),
        }

        return {
            "guideline_evidence": snippets,
            "citations": list(state.get("citations") or []) + guideline_citations,
            "routing_log": list(state.get("routing_log") or []) + [entry],
        }

    return evidence_retriever


def _make_answer_composer(llm: Any):
    def answer_composer(state: CopilotState) -> dict:
        query = _last_message_text(state)
        context_parts: list[str] = []

        if state.get("extracted_facts"):
            context_parts.append(
                "Extracted document facts:\n"
                + json.dumps(state["extracted_facts"], indent=2)[:2000]
            )

        if state.get("guideline_evidence"):
            lines = [
                f"[{s.get('source', '')} chunk {s.get('chunk_id', 0)}]: {s.get('text', '')[:300]}"
                for s in (state["guideline_evidence"] or [])[:5]
            ]
            context_parts.append("Clinical guideline evidence:\n" + "\n".join(lines))

        if not context_parts:
            context_parts.append("No additional context was retrieved.")

        user_prompt = f"User query: {query[:500]}\n\nContext:\n---\n" + "\n---\n".join(context_parts)

        try:
            parsed, usage = _llm_json(llm, _ANSWER_SYSTEM, user_prompt)
            reply = str(parsed.get("reply") or "I was unable to compose an answer from the available context.")
            new_citations = list(parsed.get("citations") or [])
        except Exception:
            _LOG.exception("answer_composer_llm_failed")
            reply = "I was unable to compose an answer. Please try again."
            new_citations, usage = [], {}

        entry = {
            "node": "answer_composer",
            "decision": "answered",
            "reason": "composed final answer from available context",
            "timestamp_ms": int(time.time() * 1000),
        }

        return {
            "final_answer": reply,
            "citations": list(state.get("citations") or []) + new_citations,
            "routing_log": list(state.get("routing_log") or []) + [entry],
            "token_usage": _merge_usage(state.get("token_usage") or {}, usage),
        }

    return answer_composer


# ---------------------------------------------------------------------------
# Routing helper (used as conditional-edge function)
# ---------------------------------------------------------------------------

def _route_from_supervisor(state: CopilotState) -> str:
    return state.get("_next_node") or "answer_composer"


# ---------------------------------------------------------------------------
# Graph builder
# ---------------------------------------------------------------------------

def build_graph(settings: Any, rag_retriever: Any = None, _llm: Any = None) -> Any:
    """Compile and return the LangGraph copilot graph.

    Pass ``_llm`` to inject a mock (useful in tests without OpenRouter).
    """
    from langgraph.graph import END, StateGraph

    if _llm is None:
        from langchain_openai import ChatOpenAI

        _llm = ChatOpenAI(
            model=settings.openrouter_model,
            api_key=settings.openrouter_api_key,
            base_url="https://openrouter.ai/api/v1",
            timeout=settings.openrouter_http_timeout_s,
            max_retries=1,
            default_headers={
                "HTTP-Referer": settings.openrouter_http_referer,
                "X-Title": settings.openrouter_app_title,
            },
        )

    workflow: StateGraph = StateGraph(CopilotState)

    workflow.add_node("supervisor", _make_supervisor(_llm))
    workflow.add_node("intake_extractor", _make_intake_extractor(_llm))
    workflow.add_node("evidence_retriever", _make_evidence_retriever(rag_retriever))
    workflow.add_node("answer_composer", _make_answer_composer(_llm))

    workflow.set_entry_point("supervisor")

    workflow.add_conditional_edges(
        "supervisor",
        _route_from_supervisor,
        {
            "intake_extractor": "intake_extractor",
            "evidence_retriever": "evidence_retriever",
            "answer_composer": "answer_composer",
        },
    )

    workflow.add_edge("intake_extractor", "supervisor")
    workflow.add_edge("evidence_retriever", "supervisor")
    workflow.add_edge("answer_composer", END)

    return workflow.compile()


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------

def run_multimodal_graph(
    message: str,
    settings: Any,
    rag_retriever: Any = None,
    patient_id: str | None = None,
    extracted_facts: dict | None = None,
    _llm: Any = None,
) -> dict[str, Any]:
    """Run the full graph and return a flat result dict."""
    graph = build_graph(settings, rag_retriever=rag_retriever, _llm=_llm)

    initial: CopilotState = {
        "messages": [{"role": "user", "content": message}],
        "patient_id": patient_id,
        "extracted_facts": extracted_facts,
        "guideline_evidence": [],
        "routing_log": [],
        "final_answer": None,
        "citations": [],
        "token_usage": {},
        "_next_node": "supervisor",
    }

    final = graph.invoke(initial)

    return {
        "reply": final.get("final_answer") or "No answer was generated.",
        "citations": final.get("citations") or [],
        "routing_log": final.get("routing_log") or [],
        "token_usage": final.get("token_usage") or {},
    }
