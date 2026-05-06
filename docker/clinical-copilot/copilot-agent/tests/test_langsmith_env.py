"""LangSmith / LangChain runtime env wiring from Settings."""

from __future__ import annotations

import os

import pytest

from app.langsmith_env import apply_langchain_runtime_env
from app.settings import Settings


def _settings_with_langsmith(
    *,
    api_key: str = "ls-test-key",
    tracing: bool = True,
    project: str = "pytest-project",
    endpoint: str = "https://example.invalid",
) -> Settings:
    return Settings(
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
        langchain_api_key=api_key,
        langchain_tracing_v2=tracing,
        langchain_project=project,
        langchain_endpoint=endpoint,
    )


def test_apply_langchain_runtime_env_sets_vars_when_configured() -> None:
    s = _settings_with_langsmith()
    apply_langchain_runtime_env(s)
    assert os.environ.get("LANGCHAIN_API_KEY") == "ls-test-key"
    assert os.environ.get("LANGCHAIN_TRACING_V2") == "true"
    assert os.environ.get("LANGCHAIN_PROJECT") == "pytest-project"
    assert os.environ.get("LANGCHAIN_ENDPOINT") == "https://example.invalid"


def test_apply_langchain_runtime_env_clears_key_when_empty(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("LANGCHAIN_API_KEY", "stale")
    s = _settings_with_langsmith(api_key="", tracing=False)
    apply_langchain_runtime_env(s)
    assert "LANGCHAIN_API_KEY" not in os.environ
    assert os.environ.get("LANGCHAIN_TRACING_V2") == "false"


def test_apply_langchain_runtime_env_omits_endpoint_when_blank(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("LANGCHAIN_ENDPOINT", "https://stale.example")
    s = _settings_with_langsmith(endpoint=" ")
    apply_langchain_runtime_env(s)
    assert "LANGCHAIN_ENDPOINT" not in os.environ


def test_langsmith_package_importable() -> None:
    """Explicit dependency so LangChain tracing client is available in deploy images."""
    import langsmith  # noqa: F401

    assert hasattr(langsmith, "Client")
