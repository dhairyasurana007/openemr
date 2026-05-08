"""FHIR DocumentReference + Observation persistence for extracted clinical documents."""

from __future__ import annotations

import base64
import hashlib
import logging
from typing import TYPE_CHECKING, Literal

import httpx
from pydantic import BaseModel

if TYPE_CHECKING:
    from app.schemas.extraction import IntakeFormResult, LabExtractionResult, LabResult
    from app.settings import Settings

_LOG = logging.getLogger("clinical_copilot.persistence")

# Maps printed abnormal flags to FHIR ObservationInterpretation codes
_ABNORMAL_FLAG_MAP: dict[str, str] = {
    "H": "H",
    "L": "L",
    "HH": "HH",
    "LL": "LL",
    "A": "A",
}

_FHIR_INTERPRETATION_SYSTEM = (
    "http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation"
)


class PersistResult(BaseModel):
    source_id: str
    """Stable FHIR DocumentReference.id (or sha256 fallback when FHIR write fails)."""
    document_reference_id: str
    observation_ids: list[str]
    """FHIR Observation ids written for lab results; empty for intake_form."""
    deduplicated: bool
    """True when the same file bytes were already stored for this patient."""


def _fhir_base_url(settings: Settings) -> str:
    base = settings.openemr_base_url().rstrip("/")
    return f"{base}/apis/default/fhir"


def _make_client(settings: Settings) -> httpx.AsyncClient:
    timeout = httpx.Timeout(
        connect=settings.openemr_http_timeout_connect_s,
        read=settings.openemr_http_timeout_read_s,
        write=settings.openemr_http_timeout_read_s,
        pool=settings.openemr_http_timeout_connect_s,
    )
    limits = httpx.Limits(
        max_connections=settings.openemr_http_max_connections,
        max_keepalive_connections=settings.openemr_http_max_keepalive,
    )
    return httpx.AsyncClient(
        timeout=timeout,
        limits=limits,
        follow_redirects=True,
        verify=settings.openemr_http_verify,
    )


def _auth_headers(settings: Settings) -> dict[str, str]:
    secret = settings.clinical_copilot_internal_secret
    if secret == "":
        return {}
    return {"X-Clinical-Copilot-Internal-Secret": secret}


async def _find_existing_doc_ref(
    client: httpx.AsyncClient,
    fhir_base: str,
    patient_id: str,
    content_hash: str,
    headers: dict[str, str],
) -> str | None:
    """Return the FHIR id of an existing DocumentReference matching patient + hash, or None."""
    params = {
        "subject": f"Patient/{patient_id}",
        "identifier": f"urn:openemr:doc-hash|sha256:{content_hash}",
    }
    try:
        resp = await client.get(f"{fhir_base}/DocumentReference", params=params, headers=headers)
        if resp.status_code == 200:
            bundle = resp.json()
            entries = bundle.get("entry", [])
            if entries:
                return str(entries[0]["resource"]["id"])
    except (httpx.HTTPError, KeyError, ValueError) as exc:
        _LOG.warning("persistence_find_doc_ref_failed: %s", exc)
    return None


async def _find_observations_for_doc_ref(
    client: httpx.AsyncClient,
    fhir_base: str,
    document_reference_id: str,
    headers: dict[str, str],
) -> list[str]:
    """Return FHIR ids of Observations whose derivedFrom points at the given DocumentReference."""
    params = {"derived-from": f"DocumentReference/{document_reference_id}"}
    try:
        resp = await client.get(f"{fhir_base}/Observation", params=params, headers=headers)
        if resp.status_code == 200:
            bundle = resp.json()
            return [str(e["resource"]["id"]) for e in bundle.get("entry", [])]
    except (httpx.HTTPError, KeyError, ValueError) as exc:
        _LOG.warning("persistence_find_observations_failed: %s", exc)
    return []


def _build_document_reference(
    patient_id: str,
    content_hash: str,
    mime_type: str,
    file_bytes: bytes,
    doc_type: str,
) -> dict:
    return {
        "resourceType": "DocumentReference",
        "status": "current",
        "subject": {"reference": f"Patient/{patient_id}"},
        "identifier": [
            {
                "system": "urn:openemr:doc-hash",
                "value": f"sha256:{content_hash}",
            }
        ],
        "description": doc_type,
        "content": [
            {
                "attachment": {
                    "contentType": mime_type,
                    "data": base64.b64encode(file_bytes).decode(),
                }
            }
        ],
    }


def _build_observation(
    patient_id: str,
    document_reference_id: str,
    lab_result: LabResult,
) -> dict:
    obs: dict = {
        "resourceType": "Observation",
        "status": "final",
        "code": {"text": lab_result.test_name},
        "subject": {"reference": f"Patient/{patient_id}"},
        "derivedFrom": [{"reference": f"DocumentReference/{document_reference_id}"}],
    }

    # Prefer valueQuantity when value parses as a float
    try:
        numeric = float(lab_result.value)
        if lab_result.unit:
            obs["valueQuantity"] = {"value": numeric, "unit": lab_result.unit}
        else:
            obs["valueQuantity"] = {"value": numeric}
    except ValueError:
        obs["valueString"] = lab_result.value

    if lab_result.reference_range:
        obs["referenceRange"] = [{"text": lab_result.reference_range}]

    if lab_result.collection_date:
        obs["effectiveDateTime"] = lab_result.collection_date

    flag = lab_result.abnormal_flag.strip().upper()
    fhir_code = _ABNORMAL_FLAG_MAP.get(flag)
    if fhir_code:
        obs["interpretation"] = [
            {
                "coding": [
                    {
                        "system": _FHIR_INTERPRETATION_SYSTEM,
                        "code": fhir_code,
                    }
                ]
            }
        ]

    return obs


async def persist_extraction(
    patient_id: str,
    file_bytes: bytes,
    mime_type: str,
    doc_type: Literal["lab_pdf", "intake_form"],
    extracted: LabExtractionResult | IntakeFormResult,
    settings: Settings,
) -> PersistResult:
    """Persist extracted document facts to OpenEMR FHIR.

    Idempotent: if the same file bytes are uploaded for the same patient,
    returns existing FHIR ids without creating new resources.
    """
    content_hash = hashlib.sha256(file_bytes).hexdigest()
    fhir_base = _fhir_base_url(settings)
    headers = _auth_headers(settings)

    async with _make_client(settings) as client:
        existing_id = await _find_existing_doc_ref(
            client, fhir_base, patient_id, content_hash, headers
        )
        if existing_id is not None:
            obs_ids = await _find_observations_for_doc_ref(
                client, fhir_base, existing_id, headers
            )
            return PersistResult(
                source_id=existing_id,
                document_reference_id=existing_id,
                observation_ids=obs_ids,
                deduplicated=True,
            )

        # Write DocumentReference
        doc_ref_body = _build_document_reference(
            patient_id, content_hash, mime_type, file_bytes, doc_type
        )
        doc_ref_id: str
        doc_ref_persisted = True
        try:
            resp = await client.post(
                f"{fhir_base}/DocumentReference", json=doc_ref_body, headers=headers
            )
            resp.raise_for_status()
            doc_ref_id = str(resp.json()["id"])
        except (httpx.HTTPError, KeyError, ValueError) as exc:
            _LOG.error("persist_doc_ref_failed patient=%s: %s", patient_id, exc)
            # Use content hash as fallback so extraction still returns a stable source_id
            doc_ref_id = f"sha256:{content_hash}"
            doc_ref_persisted = False

        # Write Observations only when DocumentReference was successfully stored;
        # without a real FHIR id, derivedFrom references would be unresolvable.
        observation_ids: list[str] = []
        if doc_type == "lab_pdf" and doc_ref_persisted:
            from app.schemas.extraction import LabExtractionResult

            if isinstance(extracted, LabExtractionResult):
                for lab_result in extracted.results:
                    obs_body = _build_observation(patient_id, doc_ref_id, lab_result)
                    try:
                        obs_resp = await client.post(
                            f"{fhir_base}/Observation", json=obs_body, headers=headers
                        )
                        obs_resp.raise_for_status()
                        observation_ids.append(str(obs_resp.json()["id"]))
                    except (httpx.HTTPError, KeyError, ValueError) as exc:
                        _LOG.warning(
                            "persist_observation_failed patient=%s test=%s: %s",
                            patient_id,
                            lab_result.test_name,
                            exc,
                        )

        return PersistResult(
            source_id=doc_ref_id,
            document_reference_id=doc_ref_id,
            observation_ids=observation_ids,
            deduplicated=False,
        )
