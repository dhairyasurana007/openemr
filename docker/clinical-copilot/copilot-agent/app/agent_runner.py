"""Two-phase agent: forced tool retrieval, then JSON-only summarization (no tools on phase 2)."""

from __future__ import annotations

import json
from datetime import date
from collections.abc import Callable
from typing import Any

from langchain_core.language_models import BaseChatModel
from langchain_core.messages import AIMessage, BaseMessage, HumanMessage, SystemMessage, ToolMessage

from app.llm_prompts import (
    GROUNDED_SUMMARY_SYSTEM_PROMPT,
    RETRIEVAL_PHASE_SYSTEM_PROMPT,
)
from app.retrieval_tools import build_retrieval_tools, parse_tool_json_content
from app.retrieval_backends import RetrievalBackend
from app.settings import Settings
from app.verification import (
    aggregate_tool_source_text,
    apply_failure_transparency_footer,
    verify_clinical_quantities_grounded,
    verify_patient_chart_request_used_tools,
    verify_tool_failures_disclosed,
)


def _default_llm_factory(settings: Settings) -> BaseChatModel:
    # OpenRouter speaks the OpenAI Chat Completions wire format; ``ChatOpenAI`` is that client—not “OpenAI models”.
    from langchain_openai import ChatOpenAI

    return ChatOpenAI(
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


_TOOL_LOOP_INSTRUCTION = (
    "Retrieval checklist:\n"
    "0) Resolve relative dates from CURRENT_DATE. Interpret 'today', 'tomorrow', 'yesterday', "
    "'this week', and similar terms using CURRENT_DATE only.\n"
    "1) Tool names must match the system prompt exactly—never ``get``/generic names.\n"
    "2) If ``patient_uuid`` is present and the question is chart data: call the needed tools among "
    "get_patient_core_profile, get_medication_list, get_observations, get_encounters_and_notes, "
    "get_referrals_orders_care_gaps.\n"
    "3) No patient UUID → do not call those five.\n"
    "4) Schedule/day/column → list_schedule_slots first.\n"
    "5) Calendar beyond slots → get_calendar with a sensible date window.\n"
    "6) Minimal tool set only. retrieval_status.ok=false → stop or try another read; never invent data.\n"
    "7) This phase: tool calls only—no assumptions."
)


def run_chat_with_tools(
    user_message: str,
    settings: Settings,
    backend: RetrievalBackend,
    *,
    llm_factory: Callable[[Settings], BaseChatModel] | None = None,
    max_tool_rounds: int = 4,
) -> tuple[str, dict[str, Any]]:
    """Force at least one tool call on the first turn, then answer **only** from retrieved JSON.

    Phase 2 uses the base chat model **without** tools so the user-facing reply cannot introduce
    new tool calls or stray from the provided ``RETRIEVED_JSON`` bundle.
    """
    tools = build_retrieval_tools(backend)
    factory = llm_factory or _default_llm_factory
    base_llm = factory(settings)
    current_date_iso = date.today().isoformat()

    bound_required = base_llm.bind_tools(tools, tool_choice="required")
    bound_auto = base_llm.bind_tools(tools, tool_choice="auto")

    messages: list[BaseMessage] = [
        SystemMessage(
            content=(
                RETRIEVAL_PHASE_SYSTEM_PROMPT
                + "\n\nCURRENT_DATE: "
                + current_date_iso
                + "\n\n"
                + _TOOL_LOOP_INSTRUCTION
            )
        ),
        HumanMessage(content="CURRENT_DATE: " + current_date_iso + "\n\n" + user_message.strip()),
    ]

    tool_payloads: list[dict[str, Any]] = []
    tools_used: list[dict[str, Any]] = []
    rounds = 0
    retrieval_truncated = False

    trace_phase1: dict[str, object] = {
        "tags": ["clinical-copilot", "v1-chat-retrieval"],
        "metadata": {
            "phase": "retrieval",
            "openrouter_model": settings.openrouter_model,
            "langsmith_project": settings.langchain_project,
        },
    }

    while rounds < max_tool_rounds:
        rounds += 1
        bound = bound_required if rounds == 1 else bound_auto
        ai: AIMessage = bound.invoke(messages, config=trace_phase1)
        messages.append(ai)

        tcalls = getattr(ai, "tool_calls", None) or []
        if not tcalls:
            if rounds == 1 and not tools_used:
                diagnostics: dict[str, Any] = {
                    "tools_used": tools_used,
                    "tool_rounds_used": rounds,
                    "tool_payload_count": 0,
                    "summarization_mode": "aborted_no_retrieval",
                    "verification": {
                        "grounding_ok": True,
                        "tool_failures_disclosed_ok": True,
                        "patient_chart_tools_ok": False,
                    },
                    "verification_findings": [
                        {
                            "code": "retrieval_tool_calls_missing",
                            "detail": "first retrieval turn did not include tool calls; refusing composed answer",
                        }
                    ],
                }
                return (
                    "Chart retrieval did not run (no tool calls from the model). "
                    "No summary can be shown from patient or schedule data.",
                    diagnostics,
                )
            break

        name_to_tool = {t.name: t for t in tools}
        allowed = sorted(name_to_tool.keys())
        for call in tcalls:
            name = call.get("name")
            raw_args = call.get("args") or {}
            args: dict[str, Any] = dict(raw_args) if isinstance(raw_args, dict) else {}
            tid = call.get("id") or name or "tool_call"
            tool_name = str(name or "")
            tool_call_id = str(tid)
            tool = name_to_tool.get(tool_name)
            if tool is None:
                tools_used.append(
                    {
                        "name": tool_name,
                        "args": args,
                        "tool_call_id": tool_call_id,
                        "status": "unknown_tool",
                    }
                )
                err = {
                    "error": "unknown_tool",
                    "tool": name,
                    "allowed_tools": allowed,
                    "hint": "Use an exact tool name from allowed_tools; there is no list-all-patients or generic get.",
                }
                messages.append(ToolMessage(content=json.dumps(err), tool_call_id=tool_call_id))
                continue
            try:
                payload = tool.invoke(args)
            except Exception as exc:
                tools_used.append(
                    {
                        "name": tool_name,
                        "args": args,
                        "tool_call_id": tool_call_id,
                        "status": "error",
                        "error": "tool_execution_exception",
                    }
                )
                err = {"error": "tool_execution_exception", "detail": str(exc)}
                messages.append(ToolMessage(content=json.dumps(err), tool_call_id=tool_call_id))
                continue
            messages.append(ToolMessage(content=payload, tool_call_id=tool_call_id))
            tools_used.append(
                {
                    "name": tool_name,
                    "args": args,
                    "tool_call_id": tool_call_id,
                    "status": "ok",
                }
            )
            parsed = parse_tool_json_content(payload)
            if parsed is not None:
                tool_payloads.append(parsed)
    else:
        retrieval_truncated = True

    retrieval_bundle: dict[str, Any] = {
        "user_question": user_message.strip(),
        "parsed_tool_results": tool_payloads,
        "tool_execution_log": tools_used,
    }
    if retrieval_truncated:
        retrieval_bundle["retrieval_warning"] = (
            "Maximum retrieval tool rounds were reached; results below may be incomplete."
        )

    human2 = (
        "Compose the clinician-facing answer using **only** RETRIEVED_JSON.\n\n"
        f"CURRENT_DATE:\n{current_date_iso}\n\n"
        f"USER_QUESTION:\n{user_message.strip()}\n\n"
        "RETRIEVED_JSON:\n"
        + json.dumps(retrieval_bundle, ensure_ascii=False)
    )
    messages2: list[BaseMessage] = [
        SystemMessage(content=GROUNDED_SUMMARY_SYSTEM_PROMPT),
        HumanMessage(content=human2),
    ]

    trace_phase2: dict[str, object] = {
        "tags": ["clinical-copilot", "v1-chat-grounded-summary"],
        "metadata": {
            "phase": "grounded_summary",
            "openrouter_model": settings.openrouter_model,
            "langsmith_project": settings.langchain_project,
        },
    }

    final_ai: AIMessage = base_llm.invoke(messages2, config=trace_phase2)
    last_text = final_ai.content if isinstance(final_ai.content, str) else str(final_ai.content)

    source_text = aggregate_tool_source_text(tool_payloads)
    grounded_issues = verify_clinical_quantities_grounded(last_text, source_text)
    last_text = apply_failure_transparency_footer(last_text, tool_payloads)
    disclosure_issues = verify_tool_failures_disclosed(last_text, tool_payloads)
    patient_tool_issues = verify_patient_chart_request_used_tools(user_message, tools_used)

    diagnostics = {
        "tools_used": tools_used,
        "tool_rounds_used": rounds,
        "tool_payload_count": len(tool_payloads),
        "summarization_mode": "json_only_two_phase",
        "retrieval_truncated": retrieval_truncated,
        "verification": {
            "grounding_ok": not grounded_issues,
            "tool_failures_disclosed_ok": not disclosure_issues,
            "patient_chart_tools_ok": not patient_tool_issues,
        },
        "verification_findings": [
            {"code": f.code, "detail": f.detail}
            for f in (*grounded_issues, *disclosure_issues, *patient_tool_issues)
        ],
    }
    return last_text, diagnostics
