"""User chat → OpenRouter (LangChain) with model-driven retrieval tools and post-verification."""

from __future__ import annotations

import asyncio
import json
import logging
import secrets
import time
from typing import Annotated, Any, Literal

from fastapi import APIRouter, File, Form, Header, HTTPException, Request, UploadFile
from pydantic import BaseModel, Field

from app.agent_runner import run_chat_with_tools
from app.document_extractor import estimate_cost_usd, extract_document
from app.output_safety import (
    check_schedule_wide_safety,
    check_uc5_draft_with_contradictory_note,
)
from app.retrieval_backends import RetrievalBackend
from app.settings import Settings

router = APIRouter(prefix="/v1", tags=["chat"])
_LOG = logging.getLogger("clinical_copilot.chat")


class CallerContext(BaseModel):
    """OpenEMR web binding forwarded from chat.php (PHI-safe keys; values may identify patients/slots)."""

    use_case: str | None = Field(default=None, max_length=8)
    patient_uuid: str | None = Field(default=None, max_length=64)
    encounter_id: str | None = Field(default=None, max_length=32)
    schedule_date: str | None = Field(default=None, max_length=10)
    authorized_slot_ids: list[str] | None = None


class ChatRequestBody(BaseModel):
    message: str = Field(min_length=1, max_length=4000)
    surface: Literal["encounter", "schedule_day", "uc5_draft"] = Field(
        default="encounter",
        description="Caller context for deterministic output-safety gates (UC1/UC6 vs UC5).",
    )
    caller_context: CallerContext | None = None


def _verify_internal_secret(settings: Settings, header_value: str | None) -> None:
    expected = settings.clinical_copilot_internal_secret
    if expected == "":
        return
    provided = (header_value or "").strip()
    try:
        ok = secrets.compare_digest(expected, provided)
    except (TypeError, ValueError):
        ok = False
    if not ok:
        raise HTTPException(status_code=403, detail="Clinical co-pilot internal authentication failed")


def _run_copilot_chat_sync(
    message: str,
    settings: Settings,
    backend: RetrievalBackend,
    use_case: str | None,
) -> tuple[str, dict[str, Any]]:
    return run_chat_with_tools(message, settings, backend, use_case=use_case)


def _output_safety_findings(surface: str, reply: str) -> list[dict[str, str]]:
    findings: list[dict[str, str]] = []
    if surface == "schedule_day":
        for f in check_schedule_wide_safety(reply):
            findings.append({"code": f.code, "detail": f.detail})
    elif surface == "uc5_draft":
        for f in check_uc5_draft_with_contradictory_note(reply):
            findings.append({"code": f.code, "detail": f.detail})
    return findings


@router.post("/chat")
async def chat(
    request: Request,
    body: ChatRequestBody,
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, Any]:
    req_start = time.perf_counter()
    request_id = (request.headers.get("X-Request-Id") or "").strip()
    if request_id == "":
        request_id = f"agent-{int(time.time() * 1000)}"
    settings: Settings = request.app.state.settings
    _verify_internal_secret(settings, x_clinical_copilot_internal_secret)

    if settings.openrouter_api_key == "":
        raise HTTPException(
            status_code=503,
            detail="OPENROUTER_API_KEY is not configured on the copilot-agent service.",
        )

    backend: RetrievalBackend = request.app.state.retrieval_backend

    user_message = body.message.strip()
    use_case = (body.caller_context.use_case or "").strip() if body.caller_context else ""
    if body.caller_context is not None:
        ctx = body.caller_context.model_dump(exclude_none=True)
        if ctx:
            user_message = (
                "[CALLER_CONTEXT]\n"
                + json.dumps(ctx, separators=(",", ":"), ensure_ascii=False)
                + "\n[/CALLER_CONTEXT]\n\n"
                + user_message
            )

    try:
        reply, diagnostics = await asyncio.to_thread(
            _run_copilot_chat_sync,
            user_message,
            settings,
            backend,
            use_case,
        )
    except HTTPException:
        raise
    except Exception as exc:
        _LOG.exception(
            "clinical_copilot_agent_chat_failed",
            extra={
                "request_id": request_id,
                "surface": body.surface,
                "total_ms": int((time.perf_counter() - req_start) * 1000.0),
            },
        )
        # Do not leak vendor or network details to clients.
        raise HTTPException(status_code=502, detail="Upstream language model request failed.") from exc

    out: dict[str, Any] = {"reply": reply}
    out["tools_used"] = diagnostics.get("tools_used") or []
    out["summarization_mode"] = diagnostics.get("summarization_mode", "")
    if diagnostics.get("retrieval_truncated") is True:
        out["retrieval_truncated"] = True
    out["verification"] = diagnostics.get("verification", {})
    vf = diagnostics.get("verification_findings") or []
    if vf:
        out["verification_findings"] = vf
    out["tool_rounds_used"] = diagnostics.get("tool_rounds_used", 0)
    out["tool_payload_count"] = diagnostics.get("tool_payload_count", 0)

    safety = _output_safety_findings(body.surface, reply)
    if safety:
        out["output_safety_findings"] = safety

    _LOG.info(
        "clinical_copilot_agent_chat_ok request_id=%s surface=%s use_case=%s total_ms=%d tool_rounds_used=%d tool_payload_count=%d summarization_mode=%s model=%s",
        request_id,
        body.surface,
        use_case,
        int((time.perf_counter() - req_start) * 1000.0),
        int(diagnostics.get("tool_rounds_used", 0)),
        int(diagnostics.get("tool_payload_count", 0)),
        str(diagnostics.get("summarization_mode", "")),
        str(diagnostics.get("openrouter_model_effective", settings.openrouter_model)),
    )

    return out


class MultimodalChatRequestBody(BaseModel):
    message: str = Field(min_length=1, max_length=4000)
    patient_id: str | None = Field(default=None, max_length=64)
    extracted_facts: dict[str, Any] | None = None
    surface: str = Field(default="encounter", max_length=32)
    use_rag: bool = True


@router.post("/multimodal-chat")
async def multimodal_chat(
    request: Request,
    body: MultimodalChatRequestBody,
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, Any]:
    req_start = time.perf_counter()
    request_id = (request.headers.get("X-Request-Id") or "").strip()
    if request_id == "":
        request_id = f"mmchat-{int(time.time() * 1000)}"
    settings: Settings = request.app.state.settings
    _verify_internal_secret(settings, x_clinical_copilot_internal_secret)

    if settings.openrouter_api_key == "":
        raise HTTPException(
            status_code=503,
            detail="OPENROUTER_API_KEY is not configured on the copilot-agent service.",
        )

    rag_active = body.use_rag and body.extracted_facts is not None
    rag_retriever = getattr(request.app.state, "rag_retriever", None) if rag_active else None

    _LOG.info(
        "clinical_copilot_multimodal_chat_start request_id=%s has_extracted_facts=%s use_rag=%s rag_active=%s msg_len=%d",
        request_id,
        body.extracted_facts is not None,
        body.use_rag,
        rag_active,
        len(body.message.strip()),
    )

    from app.multimodal_graph import run_multimodal_graph

    try:
        result = await asyncio.to_thread(
            run_multimodal_graph,
            body.message.strip(),
            settings,
            rag_retriever,
            body.patient_id,
            body.extracted_facts,
        )
    except HTTPException:
        raise
    except Exception as exc:
        _LOG.exception(
            "clinical_copilot_multimodal_chat_failed request_id=%s has_extracted_facts=%s rag_active=%s total_ms=%d",
            request_id,
            body.extracted_facts is not None,
            rag_active,
            int((time.perf_counter() - req_start) * 1000.0),
        )
        raise HTTPException(status_code=502, detail="Upstream multimodal chat request failed.") from exc

    latency_ms = int((time.perf_counter() - req_start) * 1000.0)
    token_usage = result.get("token_usage") or {}
    routing_path = ",".join(
        e.get("node", "?") + ":" + e.get("decision", "?")
        for e in (result.get("routing_log") or [])
    )
    _LOG.info(
        "clinical_copilot_multimodal_chat_ok request_id=%s latency_ms=%d routing_steps=%d routing_path=%s "
        "prompt_tokens=%d completion_tokens=%d cost_usd=%.6f evidence_count=%d",
        request_id,
        latency_ms,
        len(result.get("routing_log") or []),
        routing_path,
        token_usage.get("prompt_tokens", 0),
        token_usage.get("completion_tokens", 0),
        estimate_cost_usd(settings.openrouter_model, token_usage),
        len(result.get("guideline_evidence") or []),
    )

    return {
        "reply": result["reply"],
        "citations": result.get("citations", []),
        "routing_log": result.get("routing_log", []),
        "latency_ms": latency_ms,
        "token_usage": result.get("token_usage", {}),
        "cost_estimate_usd": estimate_cost_usd(settings.openrouter_model, result.get("token_usage", {})),
    }


_ALLOWED_DOC_TYPES = frozenset({"lab_pdf", "intake_form"})


@router.post("/extract")
async def extract(
    request: Request,
    file: UploadFile = File(...),
    doc_type: str = Form(...),
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, Any]:
    req_start = time.perf_counter()
    request_id = (request.headers.get("X-Request-Id") or "").strip()
    if request_id == "":
        request_id = f"extract-{int(time.time() * 1000)}"
    settings: Settings = request.app.state.settings
    _verify_internal_secret(settings, x_clinical_copilot_internal_secret)

    if settings.openrouter_api_key == "":
        raise HTTPException(
            status_code=503,
            detail="OPENROUTER_API_KEY is not configured on the copilot-agent service.",
        )

    if doc_type not in _ALLOWED_DOC_TYPES:
        raise HTTPException(status_code=400, detail="Invalid doc_type. Allowed: lab_pdf, intake_form")

    file_bytes = await file.read()
    mime_type = file.content_type or "application/octet-stream"

    try:
        result, token_usage, latency_ms = await extract_document(
            file_bytes=file_bytes,
            mime_type=mime_type,
            doc_type=doc_type,
            settings=settings,
        )
    except ValueError as exc:
        _LOG.warning(
            "clinical_copilot_extract_parse_error request_id=%s doc_type=%s",
            request_id,
            doc_type,
        )
        raise HTTPException(status_code=422, detail="VLM returned unparseable output.") from exc
    except Exception as exc:
        _LOG.exception(
            "clinical_copilot_extract_failed",
            extra={"request_id": request_id, "doc_type": doc_type,
                   "total_ms": int((time.perf_counter() - req_start) * 1000.0)},
        )
        raise HTTPException(status_code=502, detail="Upstream extraction request failed.") from exc

    _LOG.info(
        "clinical_copilot_extract_ok request_id=%s doc_type=%s latency_ms=%d",
        request_id,
        doc_type,
        latency_ms,
    )

    return {
        "extracted": result.model_dump(),
        "latency_ms": latency_ms,
        "token_usage": token_usage,
        "cost_estimate_usd": estimate_cost_usd(settings.vlm_model, token_usage),
    }
