"""User chat → OpenRouter (LangChain) with model-driven retrieval tools and post-verification."""

from __future__ import annotations

import asyncio
import secrets
from typing import Annotated, Any, Literal

from fastapi import APIRouter, Header, HTTPException, Request
from pydantic import BaseModel, Field

from app.agent_runner import run_chat_with_tools
from app.output_safety import (
    check_schedule_wide_safety,
    check_uc5_draft_with_contradictory_note,
)
from app.retrieval_backends import RetrievalBackend
from app.settings import Settings

router = APIRouter(prefix="/v1", tags=["chat"])


class ChatRequestBody(BaseModel):
    message: str = Field(min_length=1, max_length=4000)
    surface: Literal["encounter", "schedule_day", "uc5_draft"] = Field(
        default="encounter",
        description="Caller context for deterministic output-safety gates (UC1/UC6 vs UC5).",
    )


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


def _run_copilot_chat_sync(message: str, settings: Settings, backend: RetrievalBackend) -> tuple[str, dict[str, Any]]:
    return run_chat_with_tools(message, settings, backend)


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
    settings: Settings = request.app.state.settings
    _verify_internal_secret(settings, x_clinical_copilot_internal_secret)

    if settings.openrouter_api_key == "":
        raise HTTPException(
            status_code=503,
            detail="OPENROUTER_API_KEY is not configured on the copilot-agent service.",
        )

    backend: RetrievalBackend = request.app.state.retrieval_backend

    try:
        reply, diagnostics = await asyncio.to_thread(
            _run_copilot_chat_sync,
            body.message.strip(),
            settings,
            backend,
        )
    except HTTPException:
        raise
    except Exception as exc:
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

    return out
