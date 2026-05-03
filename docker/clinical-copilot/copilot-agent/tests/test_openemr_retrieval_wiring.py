"""Retrieval backend selection (stub vs OpenEMR HTTP)."""

from __future__ import annotations

from app.openemr_retrieval_backend import OpenEmrRetrievalBackend, retrieval_backend_for_runtime
from app.retrieval_backends import StubRetrievalBackend
from app.settings import Settings


def _settings(**overrides: object) -> Settings:
    base = dict(
        openrouter_api_key="",
        openrouter_model="anthropic/claude-3.5-haiku",
        openrouter_http_timeout_s=30.0,
        openrouter_http_referer="https://www.open-emr.org/",
        openrouter_app_title="OpenEMR Clinical Co-Pilot",
        clinical_copilot_internal_secret="",
        openemr_internal_hostport="openemr-web:80",
        openemr_http_timeout_connect_s=1.0,
        openemr_http_timeout_read_s=2.0,
        openemr_http_max_connections=4,
        openemr_http_max_keepalive=2,
        openemr_max_concurrent_requests=2,
        readyz_probe_openemr=False,
        use_openemr_retrieval=False,
        copilot_max_inflight=0,
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
