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
from app.encounter_trace import EncounterTrace, emit_trace
from app.openemr_persistence import persist_extraction
from app.request_progress import get_progress, set_progress
from app.retrieval_backends import RetrievalBackend
from app.schemas.extraction import LabExtractionResult
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


@router.get("/request-status/{request_id}")
async def request_status(
    request_id: str,
    request: Request,
    x_clinical_copilot_internal_secret: Annotated[
        str | None, Header(alias="X-Clinical-Copilot-Internal-Secret")
    ] = None,
) -> dict[str, Any]:
    settings: Settings = request.app.state.settings
    _verify_internal_secret(settings, x_clinical_copilot_internal_secret)
    item = get_progress(request_id)
    if item is None:
        return {"request_id": request_id, "known": False}
    return {"known": True, **item}


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

    set_progress(request_id, phase="started", worker="supervisor", detail="routing")

    def _on_progress(worker: str, phase: str, meta: dict[str, Any] | None = None) -> None:
        set_progress(request_id, phase=phase, worker=worker, meta=meta)

    try:
        result = await asyncio.to_thread(
            run_multimodal_graph,
            body.message.strip(),
            settings,
            backend,
            rag_retriever,
            body.patient_id,
            body.extracted_facts,
            None,
            _on_progress,
        )
    except HTTPException:
        set_progress(request_id, phase="error", worker="", detail="http_exception")
        raise
    except Exception as exc:
        _LOG.exception(
            "clinical_copilot_multimodal_chat_failed request_id=%s has_extracted_facts=%s rag_active=%s total_ms=%d",
            request_id,
            body.extracted_facts is not None,
            rag_active,
            int((time.perf_counter() - req_start) * 1000.0),
        )
        set_progress(request_id, phase="error", worker="", detail="upstream_failure")
        raise HTTPException(status_code=502, detail="Upstream multimodal chat request failed.") from exc

    latency_ms = int((time.perf_counter() - req_start) * 1000.0)
    token_usage = result.get("token_usage") or {}
    cost_usd = estimate_cost_usd(settings.openrouter_model, token_usage)
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
        cost_usd,
        len(result.get("guideline_evidence") or []),
    )

    emit_trace(
        EncounterTrace(
            request_id=request_id,
            endpoint="/v1/multimodal-chat",
            tool_sequence=[e.get("node", "") for e in (result.get("routing_log") or [])],
            step_latency_ms=result.get("step_latency_ms") or {},
            token_usage={k: int(v) for k, v in token_usage.items()},
            cost_estimate_usd=cost_usd,
            retrieval_hits=len(result.get("guideline_evidence") or []),
            extraction_confidence=None,
            eval_outcome=None,
        ),
        _LOG,
    )
    set_progress(request_id, phase="complete", worker="answer_composer", detail="done")

    return {
        "request_id": request_id,
        "reply": result["reply"],
        "citations": result.get("citations", []),
        "routing_log": result.get("routing_log", []),
        "latency_ms": latency_ms,
        "token_usage": token_usage,
        "cost_estimate_usd": cost_usd,
    }


_ALLOWED_DOC_TYPES = frozenset({"lab", "intake_form"})


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
    set_progress(request_id, phase="started", worker="intake_extractor", detail="upload_received")

    if settings.openrouter_api_key == "":
        raise HTTPException(
            status_code=503,
            detail="OPENROUTER_API_KEY is not configured on the copilot-agent service.",
        )

    if doc_type is not None and doc_type not in _ALLOWED_DOC_TYPES:
        raise HTTPException(status_code=400, detail="Invalid doc_type. Allowed: lab, intake_form")

    was_inferred = doc_type is None
    file_bytes = await file.read()
    mime_type = file.content_type or "application/octet-stream"

    try:
        set_progress(request_id, phase="running", worker="intake_extractor", detail="extracting_fields")
        result, token_usage, latency_ms = await extract_document(
            file_bytes=file_bytes,
            mime_type=mime_type,
            doc_type=doc_type,
            settings=settings,
        )
    except ValueError as exc:
        set_progress(request_id, phase="error", worker="intake_extractor", detail="parse_error")
        _LOG.warning(
            "clinical_copilot_extract_parse_error request_id=%s doc_type=%s",
            request_id,
            doc_type,
        )
        raise HTTPException(status_code=422, detail="VLM returned unparseable output.") from exc
    except Exception as exc:
        set_progress(request_id, phase="error", worker="intake_extractor", detail="upstream_failure")
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
        set_progress(request_id, phase="running", worker="intake_extractor", detail="persisting_results")
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
        if resolved_doc_type == "lab":
            for lab in extracted_dict.get("results", []):
                if isinstance(lab.get("citation"), dict):
                    lab["citation"]["source_id"] = fhir_source_id
        elif resolved_doc_type == "intake_form":
            if isinstance(extracted_dict.get("citation"), dict):
                extracted_dict["citation"]["source_id"] = fhir_source_id

    extraction_confidence: float | None = None
    if isinstance(result, LabExtractionResult) and result.results:
        extraction_confidence = min(r.confidence for r in result.results)

    cost_usd = estimate_cost_usd(settings.vlm_model, token_usage)
    emit_trace(
        EncounterTrace(
            request_id=request_id,
            endpoint=str(request.url.path),
            tool_sequence=[],
            step_latency_ms={},
            token_usage={k: int(v) for k, v in token_usage.items()},
            cost_estimate_usd=cost_usd,
            retrieval_hits=0,
            extraction_confidence=extraction_confidence,
            eval_outcome=None,
        ),
        _LOG,
    )

    response: dict[str, Any] = {
        "extracted": extracted_dict,
        "doc_type": resolved_doc_type,
        "doc_type_inferred": was_inferred,
        "latency_ms": latency_ms,
        "token_usage": token_usage,
        "cost_estimate_usd": cost_usd,
    }
    if persist_result is not None:
        response["source_id"] = persist_result.source_id
        response["observation_ids"] = persist_result.observation_ids
        response["deduplicated"] = persist_result.deduplicated

    set_progress(request_id, phase="complete", worker="intake_extractor", detail="done")

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
