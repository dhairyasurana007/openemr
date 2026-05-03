"""Tests for POST /v1/chat (OpenRouter key gating; no live LLM)."""

from __future__ import annotations

import unittest

from fastapi.testclient import TestClient

from app.settings import Settings


def _settings_no_openrouter() -> Settings:
    return Settings(
        openrouter_api_key="",
        openrouter_model="anthropic/claude-3.5-haiku",
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
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


class TestChatRouter(unittest.TestCase):
    def tearDown(self) -> None:
        import app.main as main_mod
        from app.settings import Settings

        main_mod.app.state.settings = Settings.load()

    def test_chat_returns_503_when_openrouter_key_missing(self) -> None:
        import app.main as main_mod

        main_mod.app.state.settings = _settings_no_openrouter()
        with TestClient(main_mod.app) as client:
            response = client.post("/v1/chat", json={"message": "hello"})
        self.assertEqual(response.status_code, 503)
        body = response.json()
        self.assertIn("detail", body)

    def test_chat_accepts_optional_caller_context_still_503_without_key(self) -> None:
        import app.main as main_mod

        main_mod.app.state.settings = _settings_no_openrouter()
        with TestClient(main_mod.app) as client:
            response = client.post(
                "/v1/chat",
                json={
                    "message": "hello",
                    "surface": "encounter",
                    "caller_context": {
                        "use_case": "UC2",
                        "patient_uuid": "00000000-0000-0000-0000-000000000001",
                        "encounter_id": "1",
                    },
                },
            )
        self.assertEqual(response.status_code, 503)
