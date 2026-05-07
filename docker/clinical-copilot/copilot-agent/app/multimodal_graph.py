"""LangGraph multi-agent graph: supervisor + workers (intake, chart, evidence, answer)."""

from __future__ import annotations

import json
import logging
import time
from typing import Any, TypedDict

from app.agent_runner import run_chat_with_tools
from app.llm_prompts import RAG_ANSWER_SYSTEM_PROMPT

_LOG = logging.getLogger("clinical_copilot.multimodal_graph")


class CopilotState(TypedDict):
    messages: list
    patient_id: str | None
    extracted_facts: dict | None
    intake_summary: str | None
    chart_reply: str | None
    chart_tool_payloads: list[dict]
    chart_tools_used: list[dict]
    guideline_evidence: list[dict]
    routing_log: list[dict]
    final_answer: str | None
    citations: list[dict]
    token_usage: dict
    composer_brief: dict
    _next_node: str


def _strip_fences(raw: str) -> str:
    raw = raw.strip()
    if raw.startswith("```"):
        lines = raw.splitlines()
        inner = lines[1:] if len(lines) > 1 else lines
        if inner and inner[-1].strip() == "```":
            inner = inner[:-1]
        return "\n".join(inner).strip()
    return raw


def _content_to_text(content: Any) -> str:
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        parts: list[str] = []
        for item in content:
            if isinstance(item, dict):
                text = item.get("text")
                if isinstance(text, str):
                    parts.append(text)
            else:
                parts.append(str(item))
        return "\n".join(parts)
    return str(content)


def _escape_control_chars_in_json_strings(raw: str) -> str:
    out: list[str] = []
    in_string = False
    escaped = False
    for ch in raw:
        if escaped:
            out.append(ch)
            escaped = False
            continue
        if ch == "\\":
            out.append(ch)
            escaped = True
            continue
        if ch == "\"":
            out.append(ch)
            in_string = not in_string
            continue
        if in_string and ord(ch) < 0x20:
            if ch == "\n":
                out.append("\\n")
            elif ch == "\r":
                out.append("\\r")
            elif ch == "\t":
                out.append("\\t")
            else:
                out.append(f"\\u{ord(ch):04x}")
            continue
        out.append(ch)
    return "".join(out)


def _llm_json(llm: Any, system: str, user: str) -> tuple[dict, dict]:
    from langchain_core.messages import HumanMessage, SystemMessage

    response = llm.invoke([SystemMessage(content=system), HumanMessage(content=user)])
    raw = _strip_fences(_content_to_text(response.content))
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError:
        parsed = json.loads(_escape_control_chars_in_json_strings(raw))

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


_SUPERVISOR_SYSTEM = """\
You are a clinical co-pilot routing supervisor. Decide which worker to run next given current state.
Workers:
  intake_extractor  — summarize uploaded extraction facts if present and not yet summarized.
  chart_retriever   — retrieve EHR chart/schedule facts when local patient data is needed.
  evidence_retriever — retrieve external guideline evidence (RAG) when policy/standard evidence is needed.
  answer            — compose final answer when context is sufficient.
Respond ONLY with valid JSON: {"decision":"<worker>","reason":"<one sentence>"}"""

_INTAKE_SYSTEM = """\
You are a clinical data synthesizer. Summarise the extracted document facts concisely.
Cite each clinical claim back to the source document.
Respond ONLY with valid JSON: {"summary":"<text>","citations":[{"source_type":"<str>","source_id":"<str>","page_or_section":"<str>","field_or_chunk_id":"<str>","quote_or_value":"<str>"}]}"""

_ANSWER_SYSTEM = """\
You are a clinical co-pilot answer composer. Using the provided context, write a clear, grounded answer.
Every clinical claim must be supported by a specific citation from the context.
If context is insufficient, say so explicitly rather than speculating.
Respond ONLY with valid JSON: {"reply":"<answer>","citations":[{"source_type":"<str>","source_id":"<str>","page_or_section":"<str>","field_or_chunk_id":"<str>","quote_or_value":"<str>"}]}"""

_MAX_ROUTING_STEPS = 6
_MAX_WORKER_HOPS = 2


def _dedupe_citations(citations: list[dict[str, Any]]) -> list[dict[str, Any]]:
    seen: set[tuple[str, str, str, str, str]] = set()
    out: list[dict[str, Any]] = []
    for citation in citations:
        key = (
            str(citation.get("source_type", "")),
            str(citation.get("source_id", "")),
            str(citation.get("page_or_section", "")),
            str(citation.get("field_or_chunk_id", "")),
            str(citation.get("quote_or_value", "")),
        )
        if key in seen:
            continue
        seen.add(key)
        out.append(citation)
    return out


def _extract_citations_from_facts(extracted_facts: dict[str, Any] | None) -> list[dict[str, Any]]:
    if not isinstance(extracted_facts, dict):
        return []
    out: list[dict[str, Any]] = []
    top_citation = extracted_facts.get("citation")
    if isinstance(top_citation, dict):
        out.append(dict(top_citation))
    for item in extracted_facts.get("results", []):
        if isinstance(item, dict):
            inner = item.get("citation")
            if isinstance(inner, dict):
                out.append(dict(inner))
    return _dedupe_citations(out)


def _worker_hops(state: CopilotState) -> int:
    workers = {"intake_extractor", "chart_retriever", "evidence_retriever"}
    return sum(1 for e in (state.get("routing_log") or []) if e.get("node") in workers)


def _build_composer_brief(state: CopilotState) -> dict[str, Any]:
    key_findings: list[str] = []
    if state.get("intake_summary"):
        key_findings.append(str(state["intake_summary"])[:600])
    if state.get("chart_reply"):
        key_findings.append(str(state["chart_reply"])[:600])
    evidence = state.get("guideline_evidence") or []
    for snippet in evidence[:3]:
        key_findings.append(
            f"{snippet.get('description') or snippet.get('source', 'guideline')}: "
            f"{str(snippet.get('text', ''))[:240]}"
        )
    return {
        "key_findings": key_findings,
        "chart_payload_count": len(state.get("chart_tool_payloads") or []),
        "guideline_evidence_count": len(evidence),
        "unresolved": [],
        "constraints": {
            "grounded_only": True,
            "cite_each_claim": True,
            "be_concise": True,
        },
    }


def _make_supervisor(llm: Any):
    def supervisor(state: CopilotState) -> dict:
        if len(state.get("routing_log") or []) >= _MAX_ROUTING_STEPS:
            entry = {
                "node": "supervisor",
                "decision": "answer",
                "reason": "maximum routing steps reached",
                "timestamp_ms": int(time.time() * 1000),
            }
            return {
                "routing_log": list(state.get("routing_log") or []) + [entry],
                "composer_brief": _build_composer_brief(state),
                "_next_node": "answer_composer",
            }

        hops = _worker_hops(state)
        has_patient_context = str(state.get("patient_id") or "").strip() != ""
        has_extracted = state.get("extracted_facts") is not None
        intake_done = str(state.get("intake_summary") or "").strip() != ""
        chart_done = len(state.get("chart_tool_payloads") or []) > 0
        evidence_done = bool(state.get("guideline_evidence"))

        if not has_patient_context:
            if not evidence_done and hops < _MAX_WORKER_HOPS:
                entry = {
                    "node": "supervisor",
                    "decision": "evidence_retriever",
                    "reason": "no patient context; evidence-only routing",
                    "timestamp_ms": int(time.time() * 1000),
                }
                return {
                    "routing_log": list(state.get("routing_log") or []) + [entry],
                    "_next_node": "evidence_retriever",
                }
            entry = {
                "node": "supervisor",
                "decision": "answer",
                "reason": "no patient context and evidence step complete",
                "timestamp_ms": int(time.time() * 1000),
            }
            return {
                "routing_log": list(state.get("routing_log") or []) + [entry],
                "composer_brief": _build_composer_brief(state),
                "_next_node": "answer_composer",
            }

        if hops >= _MAX_WORKER_HOPS:
            entry = {
                "node": "supervisor",
                "decision": "answer",
                "reason": "worker hop budget reached",
                "timestamp_ms": int(time.time() * 1000),
            }
            return {
                "routing_log": list(state.get("routing_log") or []) + [entry],
                "composer_brief": _build_composer_brief(state),
                "_next_node": "answer_composer",
            }

        user_ctx = (
            f"Query: {_last_message_text(state)[:500]}\n"
            f"Has extracted facts: {has_extracted}\n"
            f"Intake summary done: {intake_done}\n"
            f"Chart retrieval done: {chart_done}\n"
            f"Guideline evidence done: {evidence_done}\n"
            f"Worker hops used: {hops}/{_MAX_WORKER_HOPS}"
        )

        try:
            parsed, usage = _llm_json(llm, _SUPERVISOR_SYSTEM, user_ctx)
            decision = str(parsed.get("decision", "answer")).strip()
            reason = str(parsed.get("reason", "")).strip()
        except Exception:
            _LOG.exception("supervisor_llm_failed_fallback")
            decision, reason, usage = "answer", "supervisor error fallback", {}

        if decision == "intake_extractor" and (not has_extracted or intake_done):
            decision = "chart_retriever"
            reason = "intake not applicable; switching to chart retrieval"
        if decision == "evidence_retriever" and evidence_done:
            decision = "answer"
            reason = "evidence already present"
        if decision == "chart_retriever" and chart_done and not evidence_done:
            decision = "evidence_retriever"
            reason = "chart retrieval already completed; trying guidelines"

        node_map = {
            "intake_extractor": "intake_extractor",
            "chart_retriever": "chart_retriever",
            "evidence_retriever": "evidence_retriever",
            "answer": "answer_composer",
        }
        next_node = node_map.get(decision, "answer_composer")
        entry = {
            "node": "supervisor",
            "decision": decision,
            "reason": reason,
            "timestamp_ms": int(time.time() * 1000),
        }
        update: dict[str, Any] = {
            "routing_log": list(state.get("routing_log") or []) + [entry],
            "_next_node": next_node,
            "token_usage": _merge_usage(state.get("token_usage") or {}, usage),
        }
        if next_node == "answer_composer":
            update["composer_brief"] = _build_composer_brief(state)
        return update

    return supervisor


def _make_intake_extractor(llm: Any):
    def intake_extractor(state: CopilotState) -> dict:
        _LOG.info("worker_start node=intake_extractor")
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
            "reason": "synthesized extraction facts",
            "timestamp_ms": int(time.time() * 1000),
        }
        _LOG.info("worker_done node=intake_extractor")
        messages = list(state.get("messages") or [])
        messages.append({"role": "assistant", "content": f"[Intake summary]\n{summary}"})
        return {
            "messages": messages,
            "intake_summary": summary,
            "citations": list(state.get("citations") or []) + new_citations,
            "routing_log": list(state.get("routing_log") or []) + [entry],
            "token_usage": _merge_usage(state.get("token_usage") or {}, usage),
        }

    return intake_extractor


def _make_chart_retriever(settings: Any, backend: Any):
    def chart_retriever(state: CopilotState) -> dict:
        _LOG.info("worker_start node=chart_retriever")
        query = _last_message_text(state)
        if state.get("patient_id"):
            query = (
                "[CALLER_CONTEXT]\n"
                + json.dumps({"patient_uuid": str(state["patient_id"])}, separators=(",", ":"))
                + "\n[/CALLER_CONTEXT]\n\n"
                + query
            )
        try:
            reply, diagnostics = run_chat_with_tools(query, settings, backend)
            payloads = list(diagnostics.get("tool_payloads") or [])
            tools_used = list(diagnostics.get("tools_used") or [])
        except Exception:
            _LOG.exception("chart_retriever_failed")
            reply = "Chart retrieval worker failed."
            payloads, tools_used = [], []
        entry = {
            "node": "chart_retriever",
            "decision": f"retrieved {len(payloads)} payload(s)",
            "reason": "retrieved chart/schedule context",
            "timestamp_ms": int(time.time() * 1000),
        }
        _LOG.info("worker_done node=chart_retriever payload_count=%d", len(payloads))
        return {
            "chart_reply": reply,
            "chart_tool_payloads": payloads,
            "chart_tools_used": tools_used,
            "routing_log": list(state.get("routing_log") or []) + [entry],
        }

    return chart_retriever


def _make_evidence_retriever(rag_retriever: Any):
    def evidence_retriever(state: CopilotState) -> dict:
        _LOG.info("worker_start node=evidence_retriever")
        query = _last_message_text(state)
        snippets: list[dict] = []
        if rag_retriever is not None and query:
            try:
                snippets = rag_retriever.retrieve(query, top_k=5)
            except Exception:
                _LOG.exception("evidence_retriever_rag_failed")
        citations = [
            {
                "source_type": "guideline",
                "source_id": s.get("source", ""),
                "page_or_section": f"chunk_{s.get('chunk_id', 0)}",
                "field_or_chunk_id": str(s.get("chunk_id", 0)),
                "quote_or_value": str(s.get("text", ""))[:200],
                "url": s.get("url", ""),
                "description": s.get("description", s.get("source", "")),
            }
            for s in snippets
        ]
        entry = {
            "node": "evidence_retriever",
            "decision": f"retrieved {len(snippets)} snippet(s)",
            "reason": "retrieved guideline evidence",
            "timestamp_ms": int(time.time() * 1000),
        }
        _LOG.info("worker_done node=evidence_retriever snippet_count=%d", len(snippets))
        return {
            "guideline_evidence": snippets,
            "citations": list(state.get("citations") or []) + citations,
            "routing_log": list(state.get("routing_log") or []) + [entry],
        }

    return evidence_retriever


def _make_answer_composer(llm: Any):
    def answer_composer(state: CopilotState) -> dict:
        _LOG.info("worker_start node=answer_composer")
        query = _last_message_text(state)
        brief = state.get("composer_brief") or _build_composer_brief(state)
        context_parts: list[str] = [f"Supervisor composer brief:\n{json.dumps(brief, indent=2)[:2400]}"]
        if state.get("guideline_evidence"):
            lines = []
            for s in (state.get("guideline_evidence") or [])[:5]:
                desc = s.get("description") or s.get("source", "")
                url = s.get("url", "")
                header = f"[{desc}]({url})" if url else str(desc)
                lines.append(f"{header} - chunk {s.get('chunk_id', 0)}:\n{str(s.get('text', ''))[:400]}")
            context_parts.append("Guideline evidence:\n\n" + "\n\n".join(lines))
        if state.get("chart_tool_payloads"):
            context_parts.append(
                "Chart retrieval payloads:\n" + json.dumps(state.get("chart_tool_payloads"), indent=2)[:3000]
            )
        user_prompt = f"User query: {query[:500]}\n\nContext:\n---\n" + "\n---\n".join(context_parts)
        system_prompt = RAG_ANSWER_SYSTEM_PROMPT if state.get("guideline_evidence") else _ANSWER_SYSTEM
        try:
            parsed, usage = _llm_json(llm, system_prompt, user_prompt)
            reply = str(parsed.get("reply") or "I was unable to compose an answer from the available context.")
            new_citations = list(parsed.get("citations") or [])
        except Exception:
            _LOG.exception("answer_composer_llm_failed")
            reply = "I was unable to compose an answer. Please try again."
            new_citations, usage = [], {}
        if not new_citations:
            new_citations = list(state.get("citations") or [])
        entry = {
            "node": "answer_composer",
            "decision": "answered",
            "reason": "composed final answer from supervisor brief",
            "timestamp_ms": int(time.time() * 1000),
        }
        _LOG.info("worker_done node=answer_composer")
        return {
            "final_answer": reply,
            "citations": _dedupe_citations(list(state.get("citations") or []) + new_citations),
            "routing_log": list(state.get("routing_log") or []) + [entry],
            "token_usage": _merge_usage(state.get("token_usage") or {}, usage),
        }

    return answer_composer


def _route_from_supervisor(state: CopilotState) -> str:
    return state.get("_next_node") or "answer_composer"


def build_graph(settings: Any, backend: Any, rag_retriever: Any = None, _llm: Any = None) -> Any:
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
    workflow.add_node("chart_retriever", _make_chart_retriever(settings, backend))
    workflow.add_node("evidence_retriever", _make_evidence_retriever(rag_retriever))
    workflow.add_node("answer_composer", _make_answer_composer(_llm))
    workflow.set_entry_point("supervisor")
    workflow.add_conditional_edges(
        "supervisor",
        _route_from_supervisor,
        {
            "intake_extractor": "intake_extractor",
            "chart_retriever": "chart_retriever",
            "evidence_retriever": "evidence_retriever",
            "answer_composer": "answer_composer",
        },
    )
    workflow.add_edge("intake_extractor", "supervisor")
    workflow.add_edge("chart_retriever", "supervisor")
    workflow.add_edge("evidence_retriever", "supervisor")
    workflow.add_edge("answer_composer", END)
    return workflow.compile()


def run_multimodal_graph(
    message: str,
    settings: Any,
    backend: Any,
    rag_retriever: Any = None,
    patient_id: str | None = None,
    extracted_facts: dict | None = None,
    _llm: Any = None,
) -> dict[str, Any]:
    graph = build_graph(settings, backend, rag_retriever=rag_retriever, _llm=_llm)
    initial: CopilotState = {
        "messages": [{"role": "user", "content": message}],
        "patient_id": patient_id,
        "extracted_facts": extracted_facts,
        "intake_summary": None,
        "chart_reply": None,
        "chart_tool_payloads": [],
        "chart_tools_used": [],
        "guideline_evidence": [],
        "routing_log": [],
        "final_answer": None,
        "citations": _extract_citations_from_facts(extracted_facts),
        "token_usage": {},
        "composer_brief": {},
        "_next_node": "supervisor",
    }
    final = graph.invoke(initial)
    return {
        "reply": final.get("final_answer") or "No answer was generated.",
        "citations": final.get("citations") or [],
        "routing_log": final.get("routing_log") or [],
        "token_usage": final.get("token_usage") or {},
        "guideline_evidence": final.get("guideline_evidence") or [],
        "composer_brief": final.get("composer_brief") or {},
        "chart_tool_payload_count": len(final.get("chart_tool_payloads") or []),
    }
