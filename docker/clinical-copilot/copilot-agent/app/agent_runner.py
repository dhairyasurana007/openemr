"""Two-phase agent: forced tool retrieval, then JSON-only summarization (no tools on phase 2)."""

from __future__ import annotations

import json
import re
from concurrent.futures import ThreadPoolExecutor
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

DEFAULT_MODEL_HAIKU = "anthropic/claude-3.5-haiku"
DEFAULT_MODEL_SONNET = "anthropic/claude-sonnet-4.5"

_PATIENT_CHART_INTENT = re.compile(
    r"\b("
    r"this\s+patient|the\s+patient|patient\x27s|patient\s+chart|about\s+the\s+patient|"
    r"labs?|vitals?|meds?|medications?|allerg(y|ies)|problem\s+list|encounter\s+notes?"
    r")\b",
    re.IGNORECASE,
)


def _default_llm_factory(settings: Settings, model_override: str | None = None) -> BaseChatModel:
    # OpenRouter speaks the OpenAI Chat Completions wire format; ``ChatOpenAI`` is that client—not “OpenAI models”.
    from langchain_openai import ChatOpenAI

    return ChatOpenAI(
        model=(model_override or settings.openrouter_model),
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
    "find_patient_candidates, "
    "get_patient_core_profile, get_medication_list, get_observations, get_encounters_and_notes, "
    "get_referrals_orders_care_gaps.\n"
    "3) No patient UUID: if user gave a patient name, call find_patient_candidates first; "
    "otherwise do not call UUID-scoped patient tools.\n"
    "4) Schedule/day/column → list_schedule_slots first.\n"
    "5) Calendar beyond slots → get_calendar with a sensible date window.\n"
    "6) Minimal tool set only. retrieval_status.ok=false → stop or try another read; never invent data.\n"
    "7) This phase: tool calls only—no assumptions."
)


def _extract_caller_context(user_message: str) -> dict[str, Any]:
    start_tag = "[CALLER_CONTEXT]"
    end_tag = "[/CALLER_CONTEXT]"
    start = user_message.find(start_tag)
    end = user_message.find(end_tag)
    if start == -1 or end == -1 or end <= start:
        return {}
    body = user_message[start + len(start_tag):end].strip()
    try:
        parsed = json.loads(body)
    except (TypeError, ValueError):
        return {}
    return parsed if isinstance(parsed, dict) else {}


def _ambiguous_patient_candidates(tool_payloads: list[dict[str, Any]]) -> list[dict[str, str]]:
    for payload in reversed(tool_payloads):
        if str(payload.get("tool", "")) != "find_patient_candidates":
            continue
        status = payload.get("retrieval_status")
        if isinstance(status, dict) and status.get("ok") is False:
            return []
        raw_candidates = payload.get("candidates")
        if not isinstance(raw_candidates, list):
            return []
        candidates: list[dict[str, str]] = []
        for item in raw_candidates:
            if not isinstance(item, dict):
                continue
            display_name = str(item.get("display_name", "")).strip()
            patient_uuid = str(item.get("patient_uuid", "")).strip()
            dob = str(item.get("dob", "")).strip()
            sex = str(item.get("sex", "")).strip()
            if display_name == "" or patient_uuid == "":
                continue
            candidates.append(
                {
                    "display_name": display_name,
                    "patient_uuid": patient_uuid,
                    "dob": dob,
                    "sex": sex,
                }
            )
        if len(candidates) > 1:
            return candidates
        return []
    return []


def _invoke_tool_safe(tool: Any, args: dict[str, Any]) -> tuple[bool, str]:
    try:
        return True, str(tool.invoke(args))
    except Exception as exc:
        return False, str(exc)


def run_chat_with_tools(
    user_message: str,
    settings: Settings,
    backend: RetrievalBackend,
    *,
    llm_factory: Callable[[Settings], BaseChatModel] | None = None,
    max_tool_rounds: int = 4,
    use_case: str | None = None,
) -> tuple[str, dict[str, Any]]:
    """Force at least one tool call on the first turn, then answer **only** from retrieved JSON.

    Phase 2 uses the base chat model **without** tools so the user-facing reply cannot introduce
    new tool calls or stray from the provided ``RETRIEVED_JSON`` bundle.
    """
    tools = build_retrieval_tools(backend)
    model_for_request = DEFAULT_MODEL_HAIKU
    if (use_case or "").upper() == "UC4":
        if settings.openrouter_model_uc4.strip() != "":
            model_for_request = settings.openrouter_model_uc4
        else:
            model_for_request = DEFAULT_MODEL_SONNET
    elif settings.openrouter_model.strip() != "":
        model_for_request = settings.openrouter_model

    factory = llm_factory or _default_llm_factory
    if llm_factory is None:
        base_llm = _default_llm_factory(settings, model_for_request)
    else:
        base_llm = factory(settings)
    current_date_iso = date.today().isoformat()

    bound_required = base_llm.bind_tools(tools, tool_choice="required")
    bound_auto = base_llm.bind_tools(tools, tool_choice="auto")
    caller_context = _extract_caller_context(user_message)
    patient_uuid = str(caller_context.get("patient_uuid", "")).strip()
    has_patient_uuid = patient_uuid != ""
    patient_chart_intent = _PATIENT_CHART_INTENT.search(user_message) is not None
    force_first_tool = not (patient_chart_intent and not has_patient_uuid)

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
            "openrouter_model_effective": model_for_request,
            "langsmith_project": settings.langchain_project,
        },
    }

    while rounds < max_tool_rounds:
        rounds += 1
        bound = bound_required if rounds == 1 and force_first_tool else bound_auto
        ai: AIMessage = bound.invoke(messages, config=trace_phase1)
        messages.append(ai)

        tcalls = getattr(ai, "tool_calls", None) or []
        if not tcalls:
            if rounds == 1 and force_first_tool and not tools_used:
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
            if rounds == 1 and patient_chart_intent and not has_patient_uuid and not tools_used:
                return (
                    "I need patient context before chart retrieval. "
                    "Please include the patient name (for lookup) or open the patient chart first.",
                    {
                        "tools_used": [],
                        "tool_rounds_used": rounds,
                        "tool_payload_count": 0,
                        "summarization_mode": "aborted_missing_patient_context",
                        "verification": {
                            "grounding_ok": True,
                            "tool_failures_disclosed_ok": True,
                            "patient_chart_tools_ok": False,
                        },
                        "verification_findings": [
                            {
                                "code": "patient_context_missing",
                                "detail": "no patient_uuid in caller context and no retrieval tool call executed",
                            }
                        ],
                    },
                )
            break

        name_to_tool = {t.name: t for t in tools}
        allowed = sorted(name_to_tool.keys())
        indexed_calls = list(enumerate(tcalls))
        pending: list[tuple[int, str, str, dict[str, Any], Any]] = []
        for idx, call in indexed_calls:
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
            pending.append((idx, tool_name, tool_call_id, args, tool))

        if pending:
            max_workers = min(
                len(pending),
                max(1, int(getattr(settings, "openemr_max_concurrent_requests", 8))),
            )
            with ThreadPoolExecutor(max_workers=max_workers) as pool:
                future_map = {
                    idx: pool.submit(_invoke_tool_safe, tool, args)
                    for idx, _tool_name, _tool_call_id, args, tool in pending
                }

                call_meta = {
                    idx: (tool_name, tool_call_id, args)
                    for idx, tool_name, tool_call_id, args, _tool in pending
                }

                for idx in sorted(future_map.keys()):
                    ok, payload_or_error = future_map[idx].result()
                    tool_name, tool_call_id, args = call_meta[idx]
                    if not ok:
                        tools_used.append(
                            {
                                "name": tool_name,
                                "args": args,
                                "tool_call_id": tool_call_id,
                                "status": "error",
                                "error": "tool_execution_exception",
                            }
                        )
                        err = {"error": "tool_execution_exception", "detail": payload_or_error}
                        messages.append(ToolMessage(content=json.dumps(err), tool_call_id=tool_call_id))
                        continue
                    messages.append(ToolMessage(content=payload_or_error, tool_call_id=tool_call_id))
                    tools_used.append(
                        {
                            "name": tool_name,
                            "args": args,
                            "tool_call_id": tool_call_id,
                            "status": "ok",
                        }
                    )
                    parsed = parse_tool_json_content(payload_or_error)
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

    ambiguous_candidates = _ambiguous_patient_candidates(tool_payloads)
    if patient_chart_intent and not has_patient_uuid and ambiguous_candidates:
        option_lines = [
            f"- {c['display_name']} | DOB {c['dob'] or 'unknown'} | Sex {c['sex'] or 'unknown'} | UUID {c['patient_uuid']}"
            for c in ambiguous_candidates
        ]
        return (
            "Multiple patients match your request. Please specify which patient before chart retrieval.\n"
            + "\n".join(option_lines),
            {
                "tools_used": tools_used,
                "tool_rounds_used": rounds,
                "tool_payload_count": len(tool_payloads),
                "summarization_mode": "aborted_ambiguous_patient_candidates",
                "verification": {
                    "grounding_ok": True,
                    "tool_failures_disclosed_ok": True,
                    "patient_chart_tools_ok": False,
                },
                "verification_findings": [
                    {
                        "code": "patient_candidate_ambiguity",
                        "detail": "multiple patient candidates matched the user request; explicit disambiguation required",
                    }
                ],
                "openrouter_model_effective": model_for_request,
            },
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
            "openrouter_model_effective": model_for_request,
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
        "tool_payloads": tool_payloads,
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
        "openrouter_model_effective": model_for_request,
    }
    return last_text, diagnostics
