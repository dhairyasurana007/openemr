"""Tests for VLM document extractor (no live API calls)."""

from __future__ import annotations

import json
import unittest
from unittest.mock import AsyncMock, MagicMock, patch

import httpx
from pydantic import ValidationError

from app.document_extractor import (
    _heuristic_classify,
    _inject_source_id,
    _single_image_to_b64,
    _source_id,
    _strip_markdown_fences,
    classify_doc_type,
    estimate_cost_usd,
    extract_document,
)
from app.schemas.extraction import IntakeFormResult, LabExtractionResult
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


def _openrouter_response(content: str) -> httpx.Response:
    body = {
        "choices": [{"message": {"content": content}}],
        "usage": {"prompt_tokens": 500, "completion_tokens": 200, "total_tokens": 700},
    }
    return httpx.Response(
        200,
        json=body,
        request=httpx.Request("POST", "https://openrouter.ai/api/v1/chat/completions"),
    )


_VALID_LAB_JSON = json.dumps({
    "schema_version": "1.0.0",
    "doc_type": "lab_pdf",
    "results": [
        {
            "test_name": "Sodium",
            "value": "138",
            "unit": "mEq/L",
            "reference_range": "136-145",
            "collection_date": "2026-04-01",
            "abnormal_flag": "",
            "confidence": 0.97,
            "citation": {
                "source_type": "lab_pdf",
                "source_id": "PLACEHOLDER",
                "page_or_section": "page 1",
                "field_or_chunk_id": "sodium",
                "quote_or_value": "Na 138 mEq/L",
            },
        }
    ],
    "extraction_warnings": [],
})

_VALID_INTAKE_JSON = json.dumps({
    "schema_version": "1.0.0",
    "doc_type": "intake_form",
    "demographics": {"name": "Jane Doe", "dob": "1980-03-15", "sex": "F", "address": "123 Main St"},
    "chief_concern": "chest pain for 2 days",
    "current_medications": ["lisinopril 10 mg"],
    "allergies": ["penicillin"],
    "family_history": ["hypertension"],
    "extraction_warnings": [],
    "citation": {
        "source_type": "intake_form",
        "source_id": "PLACEHOLDER",
        "page_or_section": "page 1",
        "field_or_chunk_id": "intake_form_summary",
        "quote_or_value": "Chief concern: chest pain",
    },
})


# ---------------------------------------------------------------------------
# Sync utility tests
# ---------------------------------------------------------------------------

class TestStripMarkdownFences(unittest.TestCase):
    def test_strips_json_fence(self) -> None:
        raw = "```json\n{\"key\": 1}\n```"
        assert _strip_markdown_fences(raw) == '{"key": 1}'

    def test_strips_plain_fence(self) -> None:
        raw = "```\n{\"key\": 1}\n```"
        assert _strip_markdown_fences(raw) == '{"key": 1}'

    def test_no_fence_passthrough(self) -> None:
        raw = '{"key": 1}'
        assert _strip_markdown_fences(raw) == '{"key": 1}'

    def test_strips_whitespace(self) -> None:
        raw = "  \n{\"key\": 1}\n  "
        assert _strip_markdown_fences(raw) == '{"key": 1}'


class TestSourceId(unittest.TestCase):
    def test_starts_with_sha256_prefix(self) -> None:
        sid = _source_id(b"hello")
        assert sid.startswith("sha256:")

    def test_same_bytes_same_id(self) -> None:
        assert _source_id(b"abc") == _source_id(b"abc")

    def test_different_bytes_different_id(self) -> None:
        assert _source_id(b"abc") != _source_id(b"xyz")

    def test_length(self) -> None:
        sid = _source_id(b"hello")
        # "sha256:" + 16 hex chars
        assert len(sid) == len("sha256:") + 16


class TestSingleImageToB64(unittest.TestCase):
    def test_returns_single_item_list(self) -> None:
        result = _single_image_to_b64(b"\x89PNG", "image/png")
        assert len(result) == 1

    def test_mime_type_preserved(self) -> None:
        _, mime = _single_image_to_b64(b"data", "image/jpeg")[0]
        assert mime == "image/jpeg"

    def test_b64_decodable(self) -> None:
        import base64
        b64_data, _ = _single_image_to_b64(b"test bytes", "image/png")[0]
        assert base64.standard_b64decode(b64_data) == b"test bytes"


class TestInjectSourceId(unittest.TestCase):
    def test_injects_into_lab_result_citations(self) -> None:
        parsed = {
            "results": [
                {"citation": {"source_id": "PLACEHOLDER", "other": "x"}},
                {"citation": {"source_id": "old", "other": "y"}},
            ]
        }
        _inject_source_id(parsed, "sha256:abc123")
        for result in parsed["results"]:
            assert result["citation"]["source_id"] == "sha256:abc123"

    def test_injects_into_top_level_citation(self) -> None:
        parsed = {"citation": {"source_id": "PLACEHOLDER"}}
        _inject_source_id(parsed, "sha256:def456")
        assert parsed["citation"]["source_id"] == "sha256:def456"

    def test_no_error_when_no_citations(self) -> None:
        parsed: dict = {}
        _inject_source_id(parsed, "sha256:xyz")  # must not raise


class TestEstimateCostUsd(unittest.TestCase):
    def test_known_model_pricing(self) -> None:
        cost = estimate_cost_usd(
            "anthropic/claude-sonnet-4.6",
            {"prompt_tokens": 1_000_000, "completion_tokens": 0},
        )
        assert abs(cost - 3.0) < 1e-9

    def test_output_tokens_priced_higher(self) -> None:
        input_cost = estimate_cost_usd(
            "anthropic/claude-sonnet-4.6",
            {"prompt_tokens": 1000, "completion_tokens": 0},
        )
        output_cost = estimate_cost_usd(
            "anthropic/claude-sonnet-4.6",
            {"prompt_tokens": 0, "completion_tokens": 1000},
        )
        assert output_cost > input_cost

    def test_unknown_model_uses_default(self) -> None:
        cost = estimate_cost_usd("unknown/model", {"prompt_tokens": 100, "completion_tokens": 100})
        assert cost > 0.0

    def test_zero_tokens_zero_cost(self) -> None:
        assert estimate_cost_usd("anthropic/claude-sonnet-4.6", {}) == 0.0


# ---------------------------------------------------------------------------
# Async extraction tests (mocked httpx + mocked PDF rendering)
# ---------------------------------------------------------------------------

class TestExtractDocument(unittest.IsolatedAsyncioTestCase):
    def _mock_post(self, content: str) -> AsyncMock:
        mock = AsyncMock(return_value=_openrouter_response(content))
        return mock

    async def test_lab_pdf_image_path_returns_valid_result(self) -> None:
        settings = _settings()
        tiny_png = b"\x89PNG\r\n\x1a\n"  # minimal PNG-like bytes
        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(_VALID_LAB_JSON)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, token_usage, latency_ms = await extract_document(
                file_bytes=tiny_png,
                mime_type="image/png",
                doc_type="lab_pdf",
                settings=settings,
            )

        assert isinstance(result, LabExtractionResult)
        assert result.doc_type == "lab_pdf"
        assert len(result.results) == 1
        assert result.results[0].test_name == "Sodium"
        assert result.results[0].value == "138"
        assert token_usage["prompt_tokens"] == 500
        assert latency_ms >= 0

    async def test_intake_form_image_path_returns_valid_result(self) -> None:
        settings = _settings()
        tiny_jpg = b"\xff\xd8\xff"  # JPEG SOI marker
        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(_VALID_INTAKE_JSON)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, token_usage, latency_ms = await extract_document(
                file_bytes=tiny_jpg,
                mime_type="image/jpeg",
                doc_type="intake_form",
                settings=settings,
            )

        assert isinstance(result, IntakeFormResult)
        assert result.doc_type == "intake_form"
        assert result.chief_concern == "chest pain for 2 days"
        assert result.allergies == ["penicillin"]

    async def test_pdf_path_calls_pdf_renderer(self) -> None:
        """PDF mime type must route through _pdf_pages_to_images."""
        settings = _settings()
        fake_image = ("FAKEBASE64", "image/png")

        with patch("app.document_extractor._pdf_pages_to_images", return_value=[fake_image]) as mock_pdf, \
             patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(_VALID_LAB_JSON)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=b"%PDF-1.4",
                mime_type="application/pdf",
                doc_type="lab_pdf",
                settings=settings,
            )

        mock_pdf.assert_called_once_with(b"%PDF-1.4")
        assert isinstance(result, LabExtractionResult)

    async def test_source_id_injected_into_citations(self) -> None:
        settings = _settings()
        file_bytes = b"fake image data"
        expected_sid = _source_id(file_bytes)

        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(_VALID_LAB_JSON)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=file_bytes,
                mime_type="image/png",
                doc_type="lab_pdf",
                settings=settings,
            )

        assert isinstance(result, LabExtractionResult)
        assert result.results[0].citation.source_id == expected_sid

    async def test_invalid_json_returns_safe_partial_result(self) -> None:
        settings = _settings()
        not_json = "This is not JSON at all."

        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(not_json)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=b"img",
                mime_type="image/png",
                doc_type="lab_pdf",
                settings=settings,
            )

        assert isinstance(result, LabExtractionResult)
        assert result.doc_type == "lab_pdf"
        assert result.results == []
        assert len(result.extraction_warnings) == 1

    async def test_schema_mismatch_raises_validation_error(self) -> None:
        settings = _settings()
        bad_schema = json.dumps({"doc_type": "lab_pdf", "results": [{"test_name": "X"}]})

        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(bad_schema)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            with self.assertRaises(ValidationError):
                await extract_document(
                    file_bytes=b"img",
                    mime_type="image/png",
                    doc_type="lab_pdf",
                    settings=settings,
                )

    async def test_markdown_fences_stripped_before_parse(self) -> None:
        settings = _settings()
        fenced = f"```json\n{_VALID_LAB_JSON}\n```"

        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(fenced)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=b"img",
                mime_type="image/png",
                doc_type="lab_pdf",
                settings=settings,
            )

        assert isinstance(result, LabExtractionResult)

    async def test_schema_mode_unsupported_falls_back_to_plain_json(self) -> None:
        settings = _settings()
        tiny_jpg = b"\xff\xd8\xff"
        http_error_response = httpx.Response(
            400,
            text='{"error":"response_format unsupported"}',
            request=httpx.Request("POST", "https://openrouter.ai/api/v1/chat/completions"),
        )
        first = httpx.HTTPStatusError("unsupported", request=http_error_response.request, response=http_error_response)

        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = AsyncMock(side_effect=[first, _openrouter_response(_VALID_INTAKE_JSON)])
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=tiny_jpg,
                mime_type="image/jpeg",
                doc_type="intake_form",
                settings=settings,
            )

        assert isinstance(result, IntakeFormResult)
        assert result.doc_type == "intake_form"


# ---------------------------------------------------------------------------
# Heuristic classifier tests
# ---------------------------------------------------------------------------

class TestHeuristicClassify(unittest.TestCase):
    def test_heuristic_classifies_lab_text(self) -> None:
        text = "reference range 136-145 mEq/L abnormal flag present specimen collected"
        assert _heuristic_classify(text) == "lab_pdf"

    def test_heuristic_classifies_intake_text(self) -> None:
        text = "chief concern: headache current medications: aspirin 81mg family history: hypertension"
        assert _heuristic_classify(text) == "intake_form"

    def test_heuristic_abstains_on_ambiguous_text(self) -> None:
        assert _heuristic_classify("") is None
        assert _heuristic_classify("unrelated text with no clinical signals") is None

    def test_heuristic_requires_two_signals_for_lab(self) -> None:
        assert _heuristic_classify("reference range only") is None

    def test_heuristic_requires_two_signals_for_intake(self) -> None:
        assert _heuristic_classify("chief complaint only") is None


# ---------------------------------------------------------------------------
# classify_doc_type tests
# ---------------------------------------------------------------------------

class TestClassifyDocType(unittest.IsolatedAsyncioTestCase):
    async def test_classify_doc_type_uses_heuristic_when_text_available(self) -> None:
        settings = _settings()
        text = "reference range 136-145 mEq/L abnormal flag present specimen"
        result = await classify_doc_type(text_content=text, settings=settings)
        assert result == "lab_pdf"

    async def test_classify_doc_type_falls_back_to_vlm(self) -> None:
        settings = _settings()
        with patch("app.document_extractor._heuristic_classify", return_value=None), \
             patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = AsyncMock(return_value=_openrouter_response("intake_form"))
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result = await classify_doc_type(
                text_content="some text",
                image_payload=[b"\x89PNG\r\n\x1a\n"],
                settings=settings,
            )

        assert result == "intake_form"
        mock_client.post.assert_called_once()

    async def test_classify_doc_type_normalizes_vlm_garbage(self) -> None:
        settings = _settings()
        with patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = AsyncMock(return_value=_openrouter_response("Lab Report type"))
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result = await classify_doc_type(
                image_payload=[b"\x89PNG\r\n\x1a\n"],
                settings=settings,
            )

        assert result == "lab_pdf"

    async def test_classify_doc_type_defaults_to_lab_pdf_on_empty_payload(self) -> None:
        settings = _settings()
        result = await classify_doc_type(settings=settings)
        assert result == "lab_pdf"


# ---------------------------------------------------------------------------
# Auto-classification integration tests in extract_document
# ---------------------------------------------------------------------------

class TestExtractDocumentAutoClassify(unittest.IsolatedAsyncioTestCase):
    def _mock_post(self, content: str) -> AsyncMock:
        return AsyncMock(return_value=_openrouter_response(content))

    async def test_extract_document_calls_classifier_when_doc_type_none(self) -> None:
        settings = _settings()
        tiny_png = b"\x89PNG\r\n\x1a\n"

        with patch("app.document_extractor.classify_doc_type", new_callable=AsyncMock, return_value="lab_pdf") as mock_clf, \
             patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(_VALID_LAB_JSON)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=tiny_png,
                mime_type="image/png",
                doc_type=None,
                settings=settings,
            )

        mock_clf.assert_called_once()
        assert isinstance(result, LabExtractionResult)

    async def test_extract_document_skips_classifier_when_doc_type_provided(self) -> None:
        settings = _settings()
        tiny_png = b"\x89PNG\r\n\x1a\n"

        with patch("app.document_extractor.classify_doc_type", new_callable=AsyncMock) as mock_clf, \
             patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
            mock_client = AsyncMock()
            mock_client.post = self._mock_post(_VALID_LAB_JSON)
            mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
            mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

            result, _, _ = await extract_document(
                file_bytes=tiny_png,
                mime_type="image/png",
                doc_type="lab_pdf",
                settings=settings,
            )

        mock_clf.assert_not_called()
        assert isinstance(result, LabExtractionResult)


try:
    import fitz as _fitz_module
    _FITZ_AVAILABLE = True
except ModuleNotFoundError:
    _FITZ_AVAILABLE = False


def _make_single_tiff() -> bytes:
    """Create a minimal single-page TIFF using PyMuPDF. Requires fitz to be installed."""
    import fitz
    pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 10, 10))
    pix.set_rect(fitz.IRect(0, 0, 10, 10), (200, 200, 200))
    return pix.tobytes("tiff")


# ---------------------------------------------------------------------------
# TIFF rendering tests
# ---------------------------------------------------------------------------

class TestTiffRendering(unittest.TestCase):
    @unittest.skipUnless(_FITZ_AVAILABLE, "pymupdf not installed in this environment")
    def test_tiff_single_page_renders_one_image(self) -> None:
        from app.document_extractor import _tiff_pages_to_images
        tiff_bytes = _make_single_tiff()
        images = _tiff_pages_to_images(tiff_bytes)
        assert len(images) == 1
        _, mime = images[0]
        assert mime == "image/png"

    @unittest.skipUnless(_FITZ_AVAILABLE, "pymupdf not installed in this environment")
    def test_tiff_single_page_b64_is_decodable(self) -> None:
        import base64
        from app.document_extractor import _tiff_pages_to_images
        tiff_bytes = _make_single_tiff()
        images = _tiff_pages_to_images(tiff_bytes)
        b64_data, _ = images[0]
        decoded = base64.standard_b64decode(b64_data)
        assert len(decoded) > 0

    def test_tiff_multi_page_renders_correct_count(self) -> None:
        import sys
        from app.document_extractor import _tiff_pages_to_images

        mock_pix = MagicMock()
        mock_pix.tobytes.return_value = b"\x89PNG\r\n\x1a\n" + b"\x00" * 50
        mock_page = MagicMock()
        mock_page.get_pixmap.return_value = mock_pix

        mock_doc = [mock_page, mock_page, mock_page]
        mock_fitz = MagicMock()
        mock_fitz.open.return_value = mock_doc

        # Inject mock into sys.modules so the local `import fitz` inside the
        # function picks it up regardless of whether fitz is installed here.
        with patch.dict(sys.modules, {"fitz": mock_fitz}):
            images = _tiff_pages_to_images(b"fake-tiff-bytes")

        assert len(images) == 3
        for _, mime in images:
            assert mime == "image/png"

    def test_extract_document_routes_tiff_mime_type(self) -> None:
        """TIFF mime type must route through _tiff_pages_to_images."""
        import asyncio
        settings = _settings()
        fake_image = ("FAKEBASE64==", "image/png")

        async def _run() -> LabExtractionResult | IntakeFormResult:
            with patch("app.document_extractor._tiff_pages_to_images", return_value=[fake_image]) as mock_tiff, \
                 patch("app.document_extractor.httpx.AsyncClient") as mock_client_cls:
                mock_client = AsyncMock()
                mock_client.post = AsyncMock(return_value=_openrouter_response(_VALID_LAB_JSON))
                mock_client_cls.return_value.__aenter__ = AsyncMock(return_value=mock_client)
                mock_client_cls.return_value.__aexit__ = AsyncMock(return_value=False)

                r, _, _ = await extract_document(
                    file_bytes=b"TIFF\x00",
                    mime_type="image/tiff",
                    doc_type="lab_pdf",
                    settings=settings,
                )
            mock_tiff.assert_called_once_with(b"TIFF\x00")
            return r

        result = asyncio.run(_run())
        assert isinstance(result, LabExtractionResult)
