"""VLM-based document extraction for lab PDFs and intake forms via OpenRouter."""

from __future__ import annotations

import base64
import hashlib
import json
import logging
import re
import time
from typing import Any

import httpx

from app.schemas.extraction import IntakeFormResult, LabExtractionResult
from app.settings import Settings

_LOG = logging.getLogger("clinical_copilot.document_extractor")

# Approximate per-token USD cost for cost estimation (input_rate, output_rate).
_MODEL_COST_PER_TOKEN: dict[str, tuple[float, float]] = {
    "anthropic/claude-sonnet-4.6": (3.0e-6, 15.0e-6),
    "anthropic/claude-opus-4.7": (15.0e-6, 75.0e-6),
}
_DEFAULT_COST_PER_TOKEN: tuple[float, float] = (3.0e-6, 15.0e-6)


def _source_id(file_bytes: bytes) -> str:
    return "sha256:" + hashlib.sha256(file_bytes).hexdigest()[:16]


def _pdf_pages_to_images(file_bytes: bytes) -> list[tuple[str, str]]:
    """Render every PDF page to a base64 PNG. Returns list of (b64_data, mime_type)."""
    try:
        import fitz  # pymupdf
    except ModuleNotFoundError as exc:
        raise RuntimeError("PyMuPDF (fitz) is required for PDF extraction but is not installed.") from exc

    doc = fitz.open(stream=file_bytes, filetype="pdf")
    pages: list[tuple[str, str]] = []
    for page in doc:
        pix = page.get_pixmap(dpi=150)
        pages.append((base64.standard_b64encode(pix.tobytes("png")).decode(), "image/png"))
    return pages


def _single_image_to_b64(file_bytes: bytes, mime_type: str) -> list[tuple[str, str]]:
    """Encode a single image file as base64. Returns a single-item list."""
    return [(base64.standard_b64encode(file_bytes).decode(), mime_type)]


def _strip_markdown_fences(text: str) -> str:
    """Remove ```json … ``` fences so json.loads() can parse the body."""
    stripped = re.sub(r"^```(?:json)?\s*\n?", "", text.strip(), flags=re.IGNORECASE)
    return re.sub(r"\n?```\s*$", "", stripped).strip()


def _inject_source_id(parsed: dict[str, Any], sid: str) -> None:
    """Replace VLM source_id placeholders with the actual document hash."""
    for result in parsed.get("results", []):
        cit = result.get("citation")
        if isinstance(cit, dict):
            cit["source_id"] = sid
    cit = parsed.get("citation")
    if isinstance(cit, dict):
        cit["source_id"] = sid


def estimate_cost_usd(model: str, token_usage: dict[str, Any]) -> float:
    input_rate, output_rate = _MODEL_COST_PER_TOKEN.get(model, _DEFAULT_COST_PER_TOKEN)
    return round(
        int(token_usage.get("prompt_tokens", 0)) * input_rate
        + int(token_usage.get("completion_tokens", 0)) * output_rate,
        6,
    )


def _lab_pdf_prompt() -> str:
    return (
        "You are a medical document AI. Extract ALL laboratory results from the document image(s).\n\n"
        "Respond with ONLY valid JSON — no markdown fences, no prose — matching this schema:\n"
        '{"schema_version":"1.0.0","doc_type":"lab_pdf","results":[{"test_name":"<string>",'
        '"value":"<raw printed value>","unit":"<string or empty>","reference_range":"<string or empty>",'
        '"collection_date":"<ISO-8601 or empty>","abnormal_flag":"<H|L|HH|LL|A or empty>",'
        '"confidence":<0.0-1.0>,"citation":{"source_type":"lab_pdf","source_id":"PLACEHOLDER",'
        '"page_or_section":"<page N>","field_or_chunk_id":"<test slug>",'
        '"quote_or_value":"<verbatim text>"}}],"extraction_warnings":[]}\n\n'
        "Rules: extract every analyte visible; copy verbatim text into quote_or_value; "
        "lower confidence for unclear scans; use empty string for absent fields."
    )


def _intake_form_prompt() -> str:
    return (
        "You are a medical document AI. Extract patient information from the intake form image(s).\n\n"
        "Respond with ONLY valid JSON — no markdown fences, no prose — matching this schema:\n"
        '{"schema_version":"1.0.0","doc_type":"intake_form",'
        '"demographics":{"name":"","dob":"","sex":"","address":""},'
        '"chief_concern":"","current_medications":[],"allergies":[],"family_history":[],'
        '"extraction_warnings":[],'
        '"citation":{"source_type":"intake_form","source_id":"PLACEHOLDER",'
        '"page_or_section":"page 1","field_or_chunk_id":"intake_form_summary",'
        '"quote_or_value":"<brief verbatim excerpt>"}}\n\n'
        "Rules: empty string for absent text fields; empty list for absent list fields; "
        "include medication name and dose as written; note illegible text in extraction_warnings."
    )


async def extract_document(
    file_bytes: bytes,
    mime_type: str,
    doc_type: str,
    settings: Settings,
) -> tuple[LabExtractionResult | IntakeFormResult, dict[str, Any], int]:
    """Extract structured data from a document via VLM.

    Returns (validated_result, token_usage_dict, latency_ms).
    Raises ValueError on JSON parse failure, ValidationError on schema mismatch.
    """
    req_start = time.perf_counter()
    sid = _source_id(file_bytes)

    if mime_type == "application/pdf":
        images = _pdf_pages_to_images(file_bytes)
    else:
        images = _single_image_to_b64(file_bytes, mime_type)

    prompt = _lab_pdf_prompt() if doc_type == "lab_pdf" else _intake_form_prompt()

    content: list[dict[str, Any]] = [{"type": "text", "text": prompt}]
    for b64_data, img_mime in images:
        content.append({
            "type": "image_url",
            "image_url": {"url": f"data:{img_mime};base64,{b64_data}", "detail": "high"},
        })

    model = settings.vlm_model
    headers = {
        "Authorization": f"Bearer {settings.openrouter_api_key}",
        "Content-Type": "application/json",
        "HTTP-Referer": settings.openrouter_http_referer,
        "X-Title": settings.openrouter_app_title,
    }
    payload: dict[str, Any] = {
        "model": model,
        "messages": [{"role": "user", "content": content}],
        "max_tokens": 4096,
    }

    async with httpx.AsyncClient(timeout=settings.openrouter_http_timeout_s) as client:
        response = await client.post(
            "https://openrouter.ai/api/v1/chat/completions",
            headers=headers,
            json=payload,
        )
        try:
            response.raise_for_status()
        except httpx.HTTPStatusError as exc:
            response_body = response.text
            if len(response_body) > 2000:
                response_body = response_body[:2000] + "...<truncated>"
            _LOG.error(
                "document_extractor_openrouter_http_error status=%d model=%s doc_type=%s body=%s",
                response.status_code,
                model,
                doc_type,
                response_body,
            )
            raise exc

    resp_json = response.json()
    token_usage: dict[str, Any] = resp_json.get("usage", {})
    raw_content: str = resp_json["choices"][0]["message"]["content"]

    try:
        parsed: dict[str, Any] = json.loads(_strip_markdown_fences(raw_content))
    except json.JSONDecodeError as exc:
        output_snippet = raw_content
        if len(output_snippet) > 2000:
            output_snippet = output_snippet[:2000] + "...<truncated>"
        _LOG.warning(
            "document_extractor_parse_error doc_type=%s model=%s output=%s",
            doc_type,
            model,
            output_snippet,
        )
        raise ValueError(f"VLM returned unparseable JSON for doc_type={doc_type}") from exc

    _inject_source_id(parsed, sid)

    result: LabExtractionResult | IntakeFormResult
    if doc_type == "lab_pdf":
        result = LabExtractionResult.model_validate(parsed)
    else:
        result = IntakeFormResult.model_validate(parsed)

    latency_ms = int((time.perf_counter() - req_start) * 1000.0)
    _LOG.info(
        "document_extractor_ok doc_type=%s pages=%d model=%s latency_ms=%d prompt_tokens=%d completion_tokens=%d",
        doc_type,
        len(images),
        model,
        latency_ms,
        int(token_usage.get("prompt_tokens", 0)),
        int(token_usage.get("completion_tokens", 0)),
    )

    return result, token_usage, latency_ms
