"""User chat → OpenRouter (LangChain); called from OpenEMR web via server-to-server proxy."""

from __future__ import annotations

import asyncio
import secrets
from typing import Annotated

from fastapi import APIRouter, Header, HTTPException, Request
from pydantic import BaseModel, Field

from app.llm_prompts import SUMMARIZER_SYSTEM_PROMPT
from app.settings import Settings

router = APIRouter(prefix="/v1", tags=["chat"])


class ChatRequestBody(BaseModel):
    message: str = Field(min_length=1, max_length=4000)


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


def _invoke_llm_sync(message: str, settings: Settings) -> str:
    from langchain_core.messages import HumanMessage, SystemMessage
    from langchain_openai import ChatOpenAI

    llm = ChatOpenAI(
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
    response = llm.invoke(
        [
            SystemMessage(content=SUMMARIZER_SYSTEM_PROMPT),
            HumanMessage(content=message),
        ]
    )
    content = response.content
    if not isinstance(content, str):
        return str(content)
    return content


@router.post("/chat")
async def chat(
    request: Request,
    body: ChatRequestBody,
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, str]:
    settings: Settings = request.app.state.settings
    _verify_internal_secret(settings, x_clinical_copilot_internal_secret)

    if settings.openrouter_api_key == "":
        raise HTTPException(
            status_code=503,
            detail="OPENROUTER_API_KEY is not configured on the copilot-agent service.",
        )

    try:
        reply = await asyncio.to_thread(_invoke_llm_sync, body.message.strip(), settings)
    except HTTPException:
        raise
    except Exception as exc:
        # Do not leak vendor or network details to clients.
        raise HTTPException(status_code=502, detail="Upstream language model request failed.") from exc

    return {"reply": reply}
