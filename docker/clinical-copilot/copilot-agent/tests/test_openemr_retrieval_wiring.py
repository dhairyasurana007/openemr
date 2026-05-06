"""Retrieval backend selection (stub vs OpenEMR HTTP)."""

from __future__ import annotations

from unittest.mock import MagicMock

import httpx

from app.openemr_retrieval_backend import OpenEmrRetrievalBackend, retrieval_backend_for_runtime
from app.retrieval_backends import StubRetrievalBackend
from app.settings import Settings


def _settings(**overrides: object) -> Settings:
    base = dict(
        openrouter_api_key="",
        openrouter_model="anthropic/claude-3.5-haiku",
        openrouter_model_uc4="",
        openrouter_http_timeout_s=30.0,
        openrouter_http_referer="https://www.open-emr.org/",
        openrouter_app_title="OpenEMR Clinical Co-Pilot",
        clinical_copilot_internal_secret="",
        openemr_internal_hostport="openemr-web:80",
        openemr_standard_api_path_prefix="/apis/default/api",
        openemr_http_verify=True,
        openemr_http_timeout_connect_s=1.0,
        openemr_http_timeout_read_s=2.0,
        openemr_http_max_connections=4,
        openemr_http_max_keepalive=2,
        openemr_max_concurrent_requests=2,
        readyz_probe_openemr=False,
        use_openemr_retrieval=False,
        copilot_max_inflight=0,
        vlm_model="anthropic/claude-sonnet-4.6",
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )
    base.update(overrides)
    return Settings(**base)  # type: ignore[arg-type]


def test_retrieval_backend_for_runtime_off_is_stub() -> None:
    b = retrieval_backend_for_runtime(_settings(use_openemr_retrieval=False))
    assert isinstance(b, StubRetrievalBackend)


def test_retrieval_backend_for_runtime_on_is_openemr_client() -> None:
    b = retrieval_backend_for_runtime(_settings(use_openemr_retrieval=True))
    assert isinstance(b, OpenEmrRetrievalBackend)
    b.close()


def test_openemr_retrieval_http_paths_include_apis_default_mount() -> None:
    """Standard API is served at ``/apis/{site}/api/...``, not ``/api/...`` at the web root."""
    backend = OpenEmrRetrievalBackend(_settings(use_openemr_retrieval=True))
    mock_get = MagicMock(return_value=httpx.Response(200, json={"retrieval_status": {"ok": True}}))
    backend._client.get = mock_get  # type: ignore[method-assign]
    backend.list_schedule_slots("2024-02-21")
    path, = mock_get.call_args[0]
    assert path == "/apis/default/api/clinical-copilot/retrieval/list-schedule-slots"
    backend.close()


def test_openemr_retrieval_respects_custom_standard_api_prefix() -> None:
    backend = OpenEmrRetrievalBackend(
        _settings(use_openemr_retrieval=True, openemr_standard_api_path_prefix="/apis/demo/api")
    )
    mock_get = MagicMock(return_value=httpx.Response(200, json={"retrieval_status": {"ok": True}}))
    backend._client.get = mock_get  # type: ignore[method-assign]
    backend.get_calendar("2024-02-21")
    path, = mock_get.call_args[0]
    assert path == "/apis/demo/api/clinical-copilot/retrieval/calendar"
    backend.close()
