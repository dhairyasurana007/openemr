"""FastAPI entrypoint: JSON-backed retrieval for the Clinical Co-Pilot."""

import os
from pathlib import Path
from typing import Literal

import httpx
from fastapi import Depends, FastAPI, HTTPException
from fastapi.responses import HTMLResponse, JSONResponse, RedirectResponse
from pydantic import BaseModel, Field

from app.auth import require_admin
from app.config import COPILOT_ROOT, DATA_DIR
from app.store import data_dir_exists, load_table, patient_bundle

_PORTAL_HTML = (Path(__file__).resolve().parent / "templates" / "doctor_portal.html").read_text(encoding="utf-8")

_SYSTEM_PROMPT = (
    "You are Clinical Co-Pilot for physicians using an EHR. "
    "Be concise and practical. Do not invent patient-specific facts unless the user pasted chart context. "
    "If asked for diagnoses or prescribing, remind them you surface information only and they must exercise clinical judgment."
)

_OPENROUTER_CHAT_URL = "https://openrouter.ai/api/v1/chat/completions"


class ChatMessage(BaseModel):
    role: Literal["user", "assistant", "system"]
    content: str


class ChatRequest(BaseModel):
    messages: list[ChatMessage] = Field(..., min_length=1, max_length=40)

app = FastAPI(
    title="Clinical Co-Pilot (JSON store)",
    description="Read-only retrieval using OpenEMR-shaped JSON files; no database.",
    version="0.1.0",
)


@app.get("/", include_in_schema=False)
def root() -> HTMLResponse:
    """Doctor portal: LLM (default), Calendar, Dashboard tabs."""
    return HTMLResponse(content=_PORTAL_HTML)


@app.get("/calendar", include_in_schema=False)
def calendar_redirect() -> RedirectResponse:
    return RedirectResponse(url="/?tab=calendar", status_code=307)


@app.get("/dashboard", include_in_schema=False)
def dashboard_redirect() -> RedirectResponse:
    return RedirectResponse(url="/?tab=dashboard", status_code=307)


@app.post("/api/v1/llm/chat")
async def llm_chat(req: ChatRequest, _user: str = Depends(require_admin)) -> dict[str, object]:
    """Chat completion via OpenRouter when OPENROUTER_API_KEY is set; otherwise a demo string."""
    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    outbound: list[dict[str, str]] = [{"role": "system", "content": _SYSTEM_PROMPT}]
    for m in req.messages:
        outbound.append({"role": m.role, "content": m.content})

    if not api_key:
        last_user = next((m.content for m in reversed(req.messages) if m.role == "user"), "")
        snippet = (last_user[:180] + "…") if len(last_user) > 180 else last_user
        return {
            "reply": (
                "[Demo mode: OPENROUTER_API_KEY is not set on the server] "
                "Add your OpenRouter key in Railway (or your host) and optionally "
                "OPENROUTER_MODEL (e.g. openai/gpt-4o-mini), OPENROUTER_HTTP_REFERER, OPENROUTER_TITLE. "
                "Your message: "
                + repr(snippet)
            ),
            "demo": True,
        }

    model = os.environ.get("OPENROUTER_MODEL", "openai/gpt-4o-mini").strip() or "openai/gpt-4o-mini"
    referer = os.environ.get("OPENROUTER_HTTP_REFERER", "https://www.open-emr.org").strip()
    title = os.environ.get("OPENROUTER_TITLE", "OpenEMR Clinical Co-Pilot").strip()

    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
        "HTTP-Referer": referer,
        "X-OpenRouter-Title": title,
    }

    try:
        async with httpx.AsyncClient(timeout=90.0) as client:
            r = await client.post(
                _OPENROUTER_CHAT_URL,
                headers=headers,
                json={"model": model, "messages": outbound},
            )
    except httpx.RequestError as exc:
        raise HTTPException(status_code=502, detail=f"LLM upstream error: {exc}") from exc

    if r.status_code != 200:
        raise HTTPException(status_code=502, detail=r.text[:500])

    data = r.json()
    try:
        reply = data["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise HTTPException(status_code=502, detail="Unexpected OpenRouter response shape") from exc

    return {"reply": reply, "demo": False, "model": model, "provider": "openrouter"}


@app.get("/health")
def health() -> dict[str, str | bool]:
    return {
        "status": "ok",
        "store": "json",
        "data_dir_ready": data_dir_exists(),
    }


@app.get("/api/v1/meta")
def meta(_user: str = Depends(require_admin)) -> dict[str, object]:
    return {
        "copilot_root": str(COPILOT_ROOT.resolve()),
        "data_dir": str(DATA_DIR.resolve()),
        "tables": [
            "patient_data",
            "form_encounter",
            "lists",
            "prescriptions",
            "procedure_order",
            "procedure_report",
            "procedure_result",
            "form_vitals",
            "users",
        ],
    }


@app.get("/api/v1/table/{table_name}")
def get_table(table_name: str, _user: str = Depends(require_admin)) -> JSONResponse:
    allowed = {
        "patient_data",
        "form_encounter",
        "lists",
        "prescriptions",
        "procedure_order",
        "procedure_report",
        "procedure_result",
        "form_vitals",
        "users",
    }
    if table_name not in allowed:
        return JSONResponse(status_code=404, content={"detail": "Unknown table"})
    return JSONResponse(content=load_table(table_name))


@app.get("/api/v1/patient/{pid}/bundle")
def get_patient_bundle(pid: int, _user: str = Depends(require_admin)) -> dict[str, object]:
    return patient_bundle(pid)
