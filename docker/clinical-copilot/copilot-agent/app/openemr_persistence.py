"""FHIR DocumentReference + Observation persistence for extracted clinical documents."""

from __future__ import annotations

import base64
import hashlib
import logging
import time
import asyncio
import hmac
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
_TOKEN_CACHE: dict[str, str | float] = {"access_token": "", "expires_at": 0.0}
_TOKEN_LOCK = asyncio.Lock()
_BOOTSTRAP_LOCK = asyncio.Lock()


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


def _oauth_is_configured(settings: Settings) -> bool:
    has_static = bool(settings.openemr_oauth_client_id and settings.openemr_oauth_client_secret)
    has_bootstrap = bool(
        settings.openemr_oauth_bootstrap_enabled
        and settings.clinical_copilot_internal_secret
        and settings.openemr_oauth_bootstrap_client_id
    )
    return has_static or has_bootstrap


def _bootstrap_oauth_client_secret(settings: Settings) -> str:
    internal_secret = settings.clinical_copilot_internal_secret
    if internal_secret == "":
        raise ValueError("CLINICAL_COPILOT_INTERNAL_SECRET is required for OAuth bootstrap")
    material = f"openemr-oauth-bootstrap:{settings.openemr_oauth_bootstrap_client_id}".encode("utf-8")
    digest = hmac.new(internal_secret.encode("utf-8"), material, hashlib.sha256).digest()
    return base64.urlsafe_b64encode(digest).decode("ascii").rstrip("=")


async def _ensure_bootstrap_oauth_client(client: httpx.AsyncClient, settings: Settings) -> tuple[str, str] | None:
    if not settings.openemr_oauth_bootstrap_enabled:
        return None
    if settings.clinical_copilot_internal_secret == "":
        return None

    async with _BOOTSTRAP_LOCK:
        client_id = settings.openemr_oauth_bootstrap_client_id.strip()
        if client_id == "":
            return None
        client_secret = _bootstrap_oauth_client_secret(settings)
        base = settings.openemr_base_url().rstrip("/")
        prefix = settings.openemr_standard_api_path_prefix.rstrip("/")
        url = f"{base}{prefix}/clinical-copilot/retrieval/bootstrap-oauth-client"
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-Clinical-Copilot-Internal-Secret": settings.clinical_copilot_internal_secret,
        }
        payload = {
            "client_id": client_id,
            "client_secret": client_secret,
            "scope": settings.openemr_oauth_bootstrap_scope,
        }
        response = await client.post(url, json=payload, headers=headers)
        response.raise_for_status()
        return client_id, client_secret


def _oauth_token_url(settings: Settings) -> str:
    if settings.openemr_oauth_token_url:
        return settings.openemr_oauth_token_url
    return f"{settings.openemr_base_url().rstrip('/')}/oauth2/default/token"


async def _get_oauth_access_token(
    client: httpx.AsyncClient,
    settings: Settings,
    force_refresh: bool = False,
) -> str | None:
    local_client_id = settings.openemr_oauth_client_id
    local_client_secret = settings.openemr_oauth_client_secret
    if not local_client_id or not local_client_secret:
        bootstrap_pair = await _ensure_bootstrap_oauth_client(client, settings)
        if bootstrap_pair is not None:
            local_client_id, local_client_secret = bootstrap_pair
    if not local_client_id or not local_client_secret:
        return None

    now = time.time()
    cached_token = str(_TOKEN_CACHE.get("access_token") or "")
    cached_expiry = float(_TOKEN_CACHE.get("expires_at") or 0.0)
    if not force_refresh and cached_token != "" and (cached_expiry - 30.0) > now:
        return cached_token

    async with _TOKEN_LOCK:
        now = time.time()
        cached_token = str(_TOKEN_CACHE.get("access_token") or "")
        cached_expiry = float(_TOKEN_CACHE.get("expires_at") or 0.0)
        if not force_refresh and cached_token != "" and (cached_expiry - 30.0) > now:
            return cached_token

        form_data = {"grant_type": "client_credentials"}
        if settings.openemr_oauth_scope:
            form_data["scope"] = settings.openemr_oauth_scope

        response = await client.post(
            _oauth_token_url(settings),
            data=form_data,
            auth=(local_client_id, local_client_secret),
            headers={"Accept": "application/json"},
        )
        response.raise_for_status()
        payload = response.json()
        access_token = str(payload.get("access_token") or "").strip()
        if access_token == "":
            raise ValueError("OAuth token response missing access_token")
        expires_in = int(payload.get("expires_in") or 300)
        _TOKEN_CACHE["access_token"] = access_token
        _TOKEN_CACHE["expires_at"] = time.time() + float(max(expires_in, 60))
        return access_token


async def _auth_headers(
    client: httpx.AsyncClient,
    settings: Settings,
    force_refresh_oauth: bool = False,
) -> dict[str, str]:
    headers: dict[str, str] = {}
    secret = settings.clinical_copilot_internal_secret
    if secret != "":
        headers["X-Clinical-Copilot-Internal-Secret"] = secret
    bearer = settings.openemr_fhir_bearer_token
    if bearer != "":
        headers["Authorization"] = f"Bearer {bearer}"
        return headers

    token = await _get_oauth_access_token(client, settings, force_refresh=force_refresh_oauth)
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return headers


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
    patient_id = patient_id.strip()
    if patient_id == "":
        raise ValueError("patient_id is required for FHIR persistence")

    content_hash = hashlib.sha256(file_bytes).hexdigest()
    fhir_base = _fhir_base_url(settings)
    async with _make_client(settings) as client:
        headers = await _auth_headers(client, settings)
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
            if resp.status_code == 401 and _oauth_is_configured(settings) and settings.openemr_fhir_bearer_token == "":
                headers = await _auth_headers(client, settings, force_refresh_oauth=True)
                resp = await client.post(
                    f"{fhir_base}/DocumentReference", json=doc_ref_body, headers=headers
                )
            resp.raise_for_status()
            doc_ref_id = str(resp.json()["id"])
        except (httpx.HTTPError, KeyError, ValueError) as exc:
            detail = ""
            if isinstance(exc, httpx.HTTPStatusError) and exc.response is not None:
                detail = f" status={exc.response.status_code} body={exc.response.text[:500]}"
            _LOG.error("persist_doc_ref_failed patient=%s: %s%s", patient_id, exc, detail)
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
