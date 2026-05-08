"""Tests for FHIR DocumentReference + Observation persistence (no live OpenEMR required)."""

from __future__ import annotations

import hashlib
import json
from typing import Any
from unittest.mock import AsyncMock, MagicMock, patch

import httpx
import pytest

from app.openemr_persistence import (
    PersistResult,
    _build_document_reference,
    _build_observation,
    persist_extraction,
)
from app.schemas.extraction import (
    ExtractionCitation,
    LabExtractionResult,
    LabResult,
    IntakeFormResult,
    PatientDemographics,
)
from app.settings import Settings


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _settings() -> Settings:
    return Settings(
        openrouter_api_key="test-key",
        openrouter_model="anthropic/claude-3.5-haiku",
        openrouter_model_uc4="",
        openrouter_http_timeout_s=30.0,
        openrouter_http_referer="https://www.open-emr.org/",
        openrouter_app_title="OpenEMR Clinical Co-Pilot",
        clinical_copilot_internal_secret="",
        openemr_fhir_bearer_token="",
        openemr_oauth_token_url=None,
        openemr_oauth_client_id=None,
        openemr_oauth_client_secret=None,
        openemr_oauth_scope=None,
        openemr_oauth_bootstrap_enabled=True,
        openemr_oauth_bootstrap_client_id="clinical-copilot-agent",
        openemr_oauth_bootstrap_scope="api:fhir",
        openemr_internal_hostport="openemr-web:80",
        openemr_standard_api_path_prefix="/apis/default/api",
        openemr_http_verify=False,
        openemr_http_timeout_connect_s=1.0,
        openemr_http_timeout_read_s=2.0,
        openemr_http_max_connections=4,
        openemr_http_max_keepalive=2,
        openemr_max_concurrent_requests=2,
        readyz_probe_openemr=False,
        use_openemr_retrieval=False,
        copilot_max_inflight=0,
        vlm_model="anthropic/claude-sonnet-4.6",
        cohere_api_key="",
        embedding_model="all-MiniLM-L6-v2",
        guidelines_corpus_dir="app/guidelines",
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


def _citation() -> ExtractionCitation:
    return ExtractionCitation(
        source_type="lab",
        source_id="sha256:abc",
        page_or_section="page 1",
        field_or_chunk_id="sodium",
        quote_or_value="Na 138",
    )


def _lab_result(
    test_name: str = "Sodium",
    value: str = "138",
    unit: str = "mEq/L",
    reference_range: str = "136-145",
    collection_date: str = "2026-04-15",
    abnormal_flag: str = "",
) -> LabResult:
    return LabResult(
        test_name=test_name,
        value=value,
        unit=unit,
        reference_range=reference_range,
        collection_date=collection_date,
        abnormal_flag=abnormal_flag,
        confidence=0.95,
        citation=_citation(),
    )


def _lab_extraction(*results: LabResult) -> LabExtractionResult:
    return LabExtractionResult(results=list(results))


def _intake_extraction() -> IntakeFormResult:
    return IntakeFormResult(
        demographics=PatientDemographics(name="John Doe", dob="1980-01-01"),
        chief_concern="Annual checkup",
        citation=_citation(),
    )


_DUMMY_REQUEST = httpx.Request("POST", "http://openemr-web:80/apis/default/fhir/DocumentReference")


def _fhir_response(resource_id: str, resource_type: str = "DocumentReference") -> dict[str, Any]:
    return {"resourceType": resource_type, "id": resource_id}


def _fhir_bundle(*resources: dict[str, Any]) -> dict[str, Any]:
    return {
        "resourceType": "Bundle",
        "entry": [{"resource": r} for r in resources],
    }


def _resp(status: int, body: dict[str, Any]) -> httpx.Response:
    """Build an httpx.Response with a dummy request attached (required for raise_for_status)."""
    r = httpx.Response(status, json=body)
    r._request = _DUMMY_REQUEST  # type: ignore[attr-defined]
    return r


def _err_resp(status: int) -> httpx.Response:
    r = httpx.Response(status, text="Error")
    r._request = _DUMMY_REQUEST  # type: ignore[attr-defined]
    return r


def _make_mock_client(
    *,
    get_responses: list[httpx.Response] | None = None,
    post_responses: list[httpx.Response] | None = None,
) -> MagicMock:
    """Build a mock async httpx client for patching _make_client."""
    client = MagicMock()

    async def _aenter(*_a: Any, **_kw: Any) -> MagicMock:
        return client

    async def _aexit(*_a: Any, **_kw: Any) -> None:
        pass

    client.__aenter__ = _aenter
    client.__aexit__ = _aexit

    get_iter = iter(get_responses or [])
    post_iter = iter(post_responses or [])

    async def _get(*_a: Any, **_kw: Any) -> httpx.Response:
        return next(get_iter)

    async def _post(*_a: Any, **_kw: Any) -> httpx.Response:
        return next(post_iter)

    client.get = AsyncMock(side_effect=_get)
    client.post = AsyncMock(side_effect=_post)
    return client


# ---------------------------------------------------------------------------
# Unit tests: _build_document_reference
# ---------------------------------------------------------------------------


def test_build_document_reference_structure() -> None:
    file_bytes = b"fake-pdf"
    doc = _build_document_reference("patient-1", "abc123", "application/pdf", file_bytes, "lab")
    assert doc["resourceType"] == "DocumentReference"
    assert doc["subject"]["reference"] == "Patient/patient-1"
    assert doc["identifier"][0]["value"] == "sha256:abc123"
    assert doc["description"] == "lab"
    import base64
    assert doc["content"][0]["attachment"]["data"] == base64.b64encode(file_bytes).decode()


# ---------------------------------------------------------------------------
# Unit tests: _build_observation
# ---------------------------------------------------------------------------


def test_build_observation_numeric_value_with_unit() -> None:
    lab = _lab_result(value="138", unit="mEq/L")
    obs = _build_observation("patient-1", "doc-ref-1", lab)
    assert obs["resourceType"] == "Observation"
    assert "valueQuantity" in obs
    assert obs["valueQuantity"]["value"] == 138.0
    assert obs["valueQuantity"]["unit"] == "mEq/L"
    assert "valueString" not in obs


def test_build_observation_numeric_value_without_unit() -> None:
    lab = _lab_result(value="5.8", unit="")
    obs = _build_observation("patient-1", "doc-ref-1", lab)
    assert "valueQuantity" in obs
    assert obs["valueQuantity"]["value"] == 5.8
    assert "unit" not in obs["valueQuantity"]


def test_build_observation_non_numeric_value_uses_value_string() -> None:
    lab = _lab_result(value=">100", unit="mg/dL")
    obs = _build_observation("patient-1", "doc-ref-1", lab)
    assert "valueString" in obs
    assert obs["valueString"] == ">100"
    assert "valueQuantity" not in obs


def test_build_observation_abnormal_flag_mapped() -> None:
    lab = _lab_result(abnormal_flag="H")
    obs = _build_observation("patient-1", "doc-ref-1", lab)
    assert "interpretation" in obs
    assert obs["interpretation"][0]["coding"][0]["code"] == "H"


def test_build_observation_normal_flag_omitted() -> None:
    lab = _lab_result(abnormal_flag="")
    obs = _build_observation("patient-1", "doc-ref-1", lab)
    assert "interpretation" not in obs


def test_build_observation_derived_from_links_doc_ref() -> None:
    lab = _lab_result()
    obs = _build_observation("patient-1", "doc-ref-xyz", lab)
    assert obs["derivedFrom"] == [{"reference": "DocumentReference/doc-ref-xyz"}]


def test_build_observation_reference_range_and_date() -> None:
    lab = _lab_result(reference_range="136-145", collection_date="2026-04-15")
    obs = _build_observation("patient-1", "doc-ref-1", lab)
    assert obs["referenceRange"] == [{"text": "136-145"}]
    assert obs["effectiveDateTime"] == "2026-04-15"


# ---------------------------------------------------------------------------
# Integration tests: persist_extraction
# Only run against asyncio (trio is not installed in this environment)
# ---------------------------------------------------------------------------

pytestmark = pytest.mark.anyio


@pytest.fixture
def anyio_backend() -> str:
    return "asyncio"


async def test_first_upload_creates_doc_ref_and_observations() -> None:
    """New file → POST DocumentReference + N Observations, deduplicated=False."""
    file_bytes = b"new-lab-pdf"
    extracted = _lab_extraction(_lab_result("Sodium", "138"), _lab_result("Potassium", "5.8"))

    mock_client = _make_mock_client(
        get_responses=[
            httpx.Response(200, json=_fhir_bundle()),
        ],
        post_responses=[
            _resp(201, _fhir_response("doc-ref-new")),
            _resp(201, _fhir_response("obs-001", "Observation")),
            _resp(201, _fhir_response("obs-002", "Observation")),
        ],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        result = await persist_extraction(
            patient_id="demo-001",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="lab",
            extracted=extracted,
            settings=_settings(),
        )

    assert result.deduplicated is False
    assert result.source_id == "doc-ref-new"
    assert result.document_reference_id == "doc-ref-new"
    assert result.observation_ids == ["obs-001", "obs-002"]


async def test_second_upload_same_bytes_is_deduplicated() -> None:
    """Identical bytes for same patient → returns existing ids, no new POSTs."""
    file_bytes = b"same-lab-pdf"

    mock_client = _make_mock_client(
        get_responses=[
            httpx.Response(200, json=_fhir_bundle(_fhir_response("doc-ref-existing"))),
            httpx.Response(
                200,
                json=_fhir_bundle(
                    _fhir_response("obs-existing-1", "Observation"),
                    _fhir_response("obs-existing-2", "Observation"),
                ),
            ),
        ],
        post_responses=[],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        result = await persist_extraction(
            patient_id="demo-001",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="lab",
            extracted=_lab_extraction(_lab_result()),
            settings=_settings(),
        )

    assert result.deduplicated is True
    assert result.source_id == "doc-ref-existing"
    assert result.observation_ids == ["obs-existing-1", "obs-existing-2"]
    mock_client.post.assert_not_called()


async def test_different_patients_same_bytes_are_not_deduplicated() -> None:
    """Same file content for a *different* patient is treated as a new upload."""
    file_bytes = b"same-lab-pdf"

    mock_client = _make_mock_client(
        get_responses=[httpx.Response(200, json=_fhir_bundle())],
        post_responses=[
            _resp(201, _fhir_response("doc-ref-patient-b")),
        ],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        result = await persist_extraction(
            patient_id="patient-B",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="intake_form",
            extracted=_intake_extraction(),
            settings=_settings(),
        )

    assert result.deduplicated is False
    assert result.source_id == "doc-ref-patient-b"
    get_call_kwargs = mock_client.get.call_args[1]
    assert "Patient/patient-B" in get_call_kwargs.get("params", {}).get("subject", "")


async def test_intake_form_creates_doc_ref_only_no_observations() -> None:
    """intake_form → only DocumentReference, no Observations."""
    file_bytes = b"intake-form"
    extracted = _intake_extraction()

    mock_client = _make_mock_client(
        get_responses=[httpx.Response(200, json=_fhir_bundle())],
        post_responses=[_resp(201, _fhir_response("doc-ref-intake"))],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        result = await persist_extraction(
            patient_id="demo-001",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="intake_form",
            extracted=extracted,
            settings=_settings(),
        )

    assert result.observation_ids == []
    assert result.source_id == "doc-ref-intake"
    assert mock_client.post.call_count == 1


async def test_fhir_doc_ref_post_failure_falls_back_to_content_hash() -> None:
    """If FHIR DocumentReference POST fails, source_id is the sha256 hash fallback."""
    file_bytes = b"failing-upload"
    expected_hash = hashlib.sha256(file_bytes).hexdigest()

    mock_client = _make_mock_client(
        get_responses=[httpx.Response(200, json=_fhir_bundle())],
        post_responses=[_err_resp(500)],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        result = await persist_extraction(
            patient_id="demo-001",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="lab",
            extracted=_lab_extraction(_lab_result()),
            settings=_settings(),
        )

    assert result.source_id == f"sha256:{expected_hash}"
    assert result.deduplicated is False


async def test_observation_post_failure_does_not_crash_and_skips_id() -> None:
    """If one Observation POST fails, persist_extraction still returns without raising."""
    file_bytes = b"partial-obs"

    mock_client = _make_mock_client(
        get_responses=[httpx.Response(200, json=_fhir_bundle())],
        post_responses=[
            _resp(201, _fhir_response("doc-ref-ok")),
            _err_resp(500),
        ],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        result = await persist_extraction(
            patient_id="demo-001",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="lab",
            extracted=_lab_extraction(_lab_result()),
            settings=_settings(),
        )

    assert result.source_id == "doc-ref-ok"
    assert result.observation_ids == []


async def test_content_hash_identifier_sent_to_fhir() -> None:
    """The GET search includes the sha256 content hash in the identifier param."""
    file_bytes = b"hash-check"
    expected_hash = hashlib.sha256(file_bytes).hexdigest()

    mock_client = _make_mock_client(
        get_responses=[httpx.Response(200, json=_fhir_bundle())],
        post_responses=[_resp(201, _fhir_response("doc-ref-hash"))],
    )

    with patch("app.openemr_persistence._make_client", return_value=mock_client):
        await persist_extraction(
            patient_id="demo-001",
            file_bytes=file_bytes,
            mime_type="application/pdf",
            doc_type="intake_form",
            extracted=_intake_extraction(),
            settings=_settings(),
        )

    get_kwargs = mock_client.get.call_args[1]
    identifier_param = get_kwargs.get("params", {}).get("identifier", "")
    assert f"sha256:{expected_hash}" in identifier_param


# ---------------------------------------------------------------------------
# Chat endpoint integration: missing patient_id → 422
# ---------------------------------------------------------------------------


def test_extract_endpoint_requires_patient_id() -> None:
    """POST /v1/extract without patient_id must return 422 (FastAPI form validation)."""
    import app.main as main_mod
    from fastapi.testclient import TestClient

    with TestClient(main_mod.app) as client:
        response = client.post(
            "/v1/extract",
            files={"file": ("test.pdf", b"%PDF-1.4", "application/pdf")},
        )

    assert response.status_code == 422


def test_attach_and_extract_endpoint_requires_patient_id() -> None:
    """POST /v1/attach-and-extract without patient_id must return 422."""
    import app.main as main_mod
    from fastapi.testclient import TestClient

    with TestClient(main_mod.app) as client:
        response = client.post(
            "/v1/attach-and-extract",
            files={"file": ("test.pdf", b"%PDF-1.4", "application/pdf")},
        )

    assert response.status_code == 422
