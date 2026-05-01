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

_PATRICIA_DOSSIER = """Patient: Patricia Owens
DOB: 1968-03-14 (58 years old, female)
MRN: PO-88421
PCP: Dr. Jordan Ellis
Last visit: 2026-03-18

Chief complaint (today):
- Home blood pressures elevated past two weeks
- Exertional dyspnea after walking one block, improves with rest
- Out of metformin for five days

Vitals (intake today):
- BP 148/92 mmHg
- HR 82 bpm
- Weight 184 lb (83.5 kg); height 5 ft 6 in (168 cm); BMI 29.6
- Temp 98.2 °F (36.8 °C)
- SpO2 97% room air; RR 16/min

Allergies:
- Penicillin (rash, moderate)

Active problems:
- Essential hypertension (I10)
- Type 2 diabetes mellitus (E11.9)
- Mixed hyperlipidemia (E78.2)
- Obesity class I (E66.9)

Current medications:
- Metformin 1000 mg PO BID (last refill picked up 2026-01-12)
- Lisinopril 20 mg daily
- Atorvastatin 40 mg at bedtime
- Fluticasone nasal spray PRN
- Aspirin 81 mg daily (OTC self-report)

Discontinued / past:
- HCTZ 25 mg stopped 2019 (hypokalemia)
- Ibuprofen PRN stopped 2024 (dyspepsia)
- Glipizide stopped 2022
- Simvastatin switched to atorvastatin 2023

Recent labs:
- A1c 7.4% on 2026-02-04 (goal under 7.0)
- LDL 118 mg/dL on 2026-03-06 (above goal)
- eGFR 72 mL/min/1.73m²; creatinine 1.05 mg/dL on 2026-03-06
- Office fingerstick glucose 168 mg/dL today (non-fasting)
"""

_BRIEFING_SYSTEM = (
    "You summarize for a physician who just opened the co-pilot before walking into the room. "
    "Start with exactly this opening sentence: Next patient: Patricia Owens. "
    "Then continue in 2–4 short paragraphs of natural prose (not bullet lists): describe her as a woman with her age, "
    "chief complaint in patient-friendly terms, vitals that matter for the visit (BP, weight/BMI), allergy, "
    "medication adherence gap (metformin), and active conditions/labs only as needed for context. "
    "Use only the facts in the dossier; do not invent additional history."
)

_FALLBACK_PATRICIA_BRIEF = (
    "Next patient: Patricia Owens.\n\n"
    "Patricia is a 58-year-old woman with hypertension, type 2 diabetes, mixed hyperlipidemia, and obesity. "
    "She is here today because her home blood pressure has been running high for about two weeks, she becomes "
    "short of breath after walking a full block (improved with rest), and she has been without metformin for five days.\n\n"
    "This morning's vitals include blood pressure 148/92 mmHg, heart rate 82, weight 184 lb with BMI 29.6, temperature "
    "98.2 °F, and oxygen saturation 97% on room air. She reports a moderate penicillin allergy (rash). Current "
    "medications include metformin, lisinopril, atorvastatin, fluticasone nasal spray as needed, and daily low-dose "
    "aspirin per her report. Recent labs show A1c 7.4% in February and LDL 118 mg/dL in March; a non-fasting "
    "fingerstick glucose in the office today was 168 mg/dL.\n\n"
    "Use the chat below for follow-up questions when you are ready."
)


async def _openrouter_complete(outbound: list[dict[str, str]]) -> dict[str, object]:
    """POST outbound messages to OpenRouter. Callers must ensure OPENROUTER_API_KEY is set."""
    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if not api_key:
        raise HTTPException(status_code=500, detail="OpenRouter API key missing (internal)")

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


@app.post("/api/v1/llm/next-patient-brief")
async def next_patient_brief(_user: str = Depends(require_admin)) -> dict[str, object]:
    """Opening narration for Patricia Owens using dashboard dossier (OpenRouter or built-in fallback)."""
    outbound: list[dict[str, str]] = [
        {"role": "system", "content": _BRIEFING_SYSTEM},
        {
            "role": "user",
            "content": "Here is the structured dossier for today's visit:\n\n" + _PATRICIA_DOSSIER,
        },
    ]
    if not os.environ.get("OPENROUTER_API_KEY", "").strip():
        return {"reply": _FALLBACK_PATRICIA_BRIEF, "demo": True, "provider": "fallback"}

    return await _openrouter_complete(outbound)


@app.post("/api/v1/llm/chat")
async def llm_chat(req: ChatRequest, _user: str = Depends(require_admin)) -> dict[str, object]:
    """Chat completion via OpenRouter when OPENROUTER_API_KEY is set; otherwise a demo string."""
    outbound: list[dict[str, str]] = [{"role": "system", "content": _SYSTEM_PROMPT}]
    for m in req.messages:
        outbound.append({"role": m.role, "content": m.content})

    if not os.environ.get("OPENROUTER_API_KEY", "").strip():
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

    return await _openrouter_complete(outbound)


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
