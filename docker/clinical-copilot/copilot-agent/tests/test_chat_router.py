"""Tests for chat endpoints (OpenRouter key gating; no live LLM)."""

from __future__ import annotations

import json
import unittest
from unittest.mock import AsyncMock, patch

from fastapi.testclient import TestClient

from app.settings import Settings


def _settings_no_openrouter() -> Settings:
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
        cohere_api_key="",
        embedding_model="all-MiniLM-L6-v2",
        guidelines_corpus_dir="app/guidelines",
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


def _settings_with_openrouter() -> Settings:
    return Settings(
        openrouter_api_key="test-key",
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
        cohere_api_key="",
        embedding_model="all-MiniLM-L6-v2",
        guidelines_corpus_dir="app/guidelines",
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


def _make_lab_result() -> "LabExtractionResult":
    from app.schemas.extraction import ExtractionCitation, LabExtractionResult, LabResult
    return LabExtractionResult(
        results=[
            LabResult(
                test_name="Sodium",
                value="138",
                unit="mEq/L",
                reference_range="136-145",
                collection_date="2026-04-01",
                abnormal_flag="",
                confidence=0.95,
                citation=ExtractionCitation(
                    source_type="lab_pdf",
                    source_id="sha256:abc123",
                    page_or_section="page 1",
                    field_or_chunk_id="sodium",
                    quote_or_value="Na 138",
                ),
            )
        ]
    )


class TestChatRouter(unittest.TestCase):
    def tearDown(self) -> None:
        import app.main as main_mod
        from app.settings import Settings

        main_mod.app.state.settings = Settings.load()

    def test_multimodal_chat_returns_503_when_openrouter_key_missing(self) -> None:
        import app.main as main_mod

        main_mod.app.state.settings = _settings_no_openrouter()
        with TestClient(main_mod.app) as client:
            response = client.post("/v1/multimodal-chat", json={"message": "hello"})
        self.assertEqual(response.status_code, 503)
        body = response.json()
        self.assertIn("detail", body)

    def test_legacy_chat_endpoint_is_removed(self) -> None:
        import app.main as main_mod

        main_mod.app.state.settings = _settings_no_openrouter()
        with TestClient(main_mod.app) as client:
            response = client.post("/v1/chat", json={"message": "hello"})
        self.assertEqual(response.status_code, 404)


class TestExtractEndpoint(unittest.TestCase):
    def tearDown(self) -> None:
        import app.main as main_mod
        main_mod.app.state.settings = Settings.load()

    def test_extract_endpoint_accepts_missing_doc_type(self) -> None:
        import app.main as main_mod

        mock_result = _make_lab_result()

        with patch("app.chat.extract_document", new=AsyncMock(return_value=(mock_result, {}, 100))):
            with TestClient(main_mod.app) as client:
                # Override AFTER lifespan runs so our key isn't reset
                main_mod.app.state.settings = _settings_with_openrouter()
                response = client.post(
                    "/v1/extract",
                    files={"file": ("test.pdf", b"%PDF-1.4", "application/pdf")},
                )

        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertIn("doc_type", body)
        self.assertIn("doc_type_inferred", body)
        self.assertTrue(body["doc_type_inferred"])
        self.assertEqual(body["doc_type"], "lab_pdf")

    def test_extract_endpoint_pinned_doc_type_short_circuits_classifier(self) -> None:
        import app.main as main_mod

        mock_result = _make_lab_result()

        with patch("app.chat.extract_document", new=AsyncMock(return_value=(mock_result, {}, 100))), \
             patch("app.document_extractor.classify_doc_type", new_callable=AsyncMock) as mock_clf:
            with TestClient(main_mod.app) as client:
                main_mod.app.state.settings = _settings_with_openrouter()
                response = client.post(
                    "/v1/extract",
                    data={"doc_type": "lab_pdf"},
                    files={"file": ("test.pdf", b"%PDF-1.4", "application/pdf")},
                )

        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertFalse(body["doc_type_inferred"])
        mock_clf.assert_not_called()

    def test_extract_endpoint_rejects_invalid_explicit_doc_type(self) -> None:
        import app.main as main_mod

        with TestClient(main_mod.app) as client:
            main_mod.app.state.settings = _settings_with_openrouter()
            response = client.post(
                "/v1/extract",
                data={"doc_type": "bad_type"},
                files={"file": ("test.pdf", b"%PDF-1.4", "application/pdf")},
            )

        self.assertEqual(response.status_code, 400)

    def test_extract_endpoint_returns_503_when_openrouter_key_missing(self) -> None:
        import app.main as main_mod

        with TestClient(main_mod.app) as client:
            response = client.post(
                "/v1/extract",
                files={"file": ("test.pdf", b"%PDF-1.4", "application/pdf")},
            )

        self.assertEqual(response.status_code, 503)
