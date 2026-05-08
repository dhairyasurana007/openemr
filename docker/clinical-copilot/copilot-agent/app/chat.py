"""User chat → OpenRouter (LangChain) with model-driven retrieval tools and post-verification."""

from __future__ import annotations

import asyncio
import logging
import secrets
import time
from typing import Annotated, Any

from fastapi import APIRouter, File, Form, Header, HTTPException, Request, UploadFile
from pydantic import BaseModel, Field

from app.document_extractor import estimate_cost_usd, extract_document
from app.openemr_persistence import persist_extraction
from app.retrieval_backends import RetrievalBackend
from app.settings import Settings

router = APIRouter(prefix="/v1", tags=["chat"])
_LOG = logging.getLogger("clinical_copilot.chat")


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


class MultimodalChatRequestBody(BaseModel):
    message: str = Field(min_length=1, max_length=4000)
    patient_id: str | None = Field(default=None, max_length=64)
    extracted_facts: dict[str, Any] | None = None
    source_id: str | None = Field(default=None, max_length=256)
    """FHIR DocumentReference.id from a prior /v1/attach-and-extract call."""
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

    rag_active = body.use_rag
    rag_retriever = getattr(request.app.state, "rag_retriever", None) if rag_active else None
    backend: RetrievalBackend = request.app.state.retrieval_backend

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
            backend,
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


async def _extract_handler(
    request: Request,
    file: UploadFile,
    doc_type: str | None,
    patient_id: str,
    x_clinical_copilot_internal_secret: str | None,
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

    if doc_type is not None and doc_type not in _ALLOWED_DOC_TYPES:
        raise HTTPException(status_code=400, detail="Invalid doc_type. Allowed: lab_pdf, intake_form")

    was_inferred = doc_type is None
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

    resolved_doc_type = result.doc_type

    # Persist to FHIR; non-fatal if the OpenEMR stack is unavailable
    persist_result = None
    try:
        persist_result = await persist_extraction(
            patient_id=patient_id,
            file_bytes=file_bytes,
            mime_type=mime_type,
            doc_type=resolved_doc_type,  # type: ignore[arg-type]
            extracted=result,
            settings=settings,
        )
    except Exception as exc:
        _LOG.warning(
            "clinical_copilot_persist_failed request_id=%s patient_id=%s: %s",
            request_id,
            patient_id,
            exc,
        )

    _LOG.info(
        "clinical_copilot_extract_ok request_id=%s doc_type=%s doc_type_inferred=%s "
        "latency_ms=%d deduplicated=%s source_id=%s",
        request_id,
        resolved_doc_type,
        was_inferred,
        latency_ms,
        persist_result.deduplicated if persist_result else None,
        persist_result.source_id if persist_result else None,
    )

    extracted_dict = result.model_dump()

    # Backfill citation source_id with the stable FHIR DocumentReference.id so
    # downstream consumers (bbox overlay, eval runner) can resolve citations to
    # the persisted resource rather than a VLM-generated placeholder.
    if persist_result is not None:
        fhir_source_id = persist_result.source_id
        if resolved_doc_type == "lab_pdf":
            for lab in extracted_dict.get("results", []):
                if isinstance(lab.get("citation"), dict):
                    lab["citation"]["source_id"] = fhir_source_id
        elif resolved_doc_type == "intake_form":
            if isinstance(extracted_dict.get("citation"), dict):
                extracted_dict["citation"]["source_id"] = fhir_source_id

    response: dict[str, Any] = {
        "extracted": extracted_dict,
        "doc_type": resolved_doc_type,
        "doc_type_inferred": was_inferred,
        "latency_ms": latency_ms,
        "token_usage": token_usage,
        "cost_estimate_usd": estimate_cost_usd(settings.vlm_model, token_usage),
    }
    if persist_result is not None:
        response["source_id"] = persist_result.source_id
        response["observation_ids"] = persist_result.observation_ids
        response["deduplicated"] = persist_result.deduplicated

    return response


@router.post("/extract")
async def extract(
    request: Request,
    file: UploadFile = File(...),
    doc_type: str | None = Form(default=None),
    patient_id: str = Form(...),
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, Any]:
    return await _extract_handler(request, file, doc_type, patient_id, x_clinical_copilot_internal_secret)


@router.post("/attach-and-extract")
async def attach_and_extract(
    request: Request,
    file: UploadFile = File(...),
    doc_type: str | None = Form(default=None),
    patient_id: str = Form(...),
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, Any]:
    return await _extract_handler(request, file, doc_type, patient_id, x_clinical_copilot_internal_secret)
