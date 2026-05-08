"""Tests for per-encounter structured trace (C5)."""

from __future__ import annotations

import json
import logging
import os

import pytest

from app.encounter_trace import EncounterTrace, emit_trace
from app.log_redaction import PHIRedactionFilter


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _make_trace(**overrides: object) -> EncounterTrace:
    defaults: dict = {
        "request_id": "test-req-001",
        "endpoint": "/v1/multimodal-chat",
        "tool_sequence": ["supervisor", "evidence_retriever", "answer_composer"],
        "step_latency_ms": {"evidence_retriever": 120, "answer_composer": 210},
        "token_usage": {"prompt_tokens": 200, "completion_tokens": 80, "total_tokens": 280},
        "cost_estimate_usd": 0.00042,
        "retrieval_hits": 3,
        "extraction_confidence": None,
        "eval_outcome": None,
    }
    defaults.update(overrides)
    return EncounterTrace(**defaults)  # type: ignore[arg-type]


# ---------------------------------------------------------------------------
# Schema — all required fields present
# ---------------------------------------------------------------------------


REQUIRED_FIELDS = [
    "request_id",
    "endpoint",
    "tool_sequence",
    "step_latency_ms",
    "token_usage",
    "cost_estimate_usd",
    "retrieval_hits",
]


def test_encounter_trace_has_all_required_fields() -> None:
    trace = _make_trace()
    d = trace.model_dump()
    for field in REQUIRED_FIELDS:
        assert field in d, f"Missing required field: {field}"


def test_encounter_trace_optional_fields_default_to_none() -> None:
    trace = _make_trace()
    assert trace.extraction_confidence is None
    assert trace.eval_outcome is None


def test_phi_redacted_defaults_to_true() -> None:
    trace = _make_trace()
    assert trace.phi_redacted is True


def test_encounter_trace_extraction_confidence_accepts_float() -> None:
    trace = _make_trace(extraction_confidence=0.87)
    assert trace.extraction_confidence == pytest.approx(0.87)


def test_encounter_trace_eval_outcome_can_be_set() -> None:
    trace = _make_trace(eval_outcome="pass")
    assert trace.eval_outcome == "pass"


# ---------------------------------------------------------------------------
# PHI safety — synthetic patient must not appear in serialised trace
# ---------------------------------------------------------------------------

_SYNTHETIC_PHI = [
    "John Doe",
    "1980-01-01",
    "MRN demo-001",
    "555-12-1234",
]


def test_trace_dict_contains_no_synthetic_phi() -> None:
    """Computed fields (counts, costs, latencies) should never embed raw PHI."""
    trace = _make_trace()
    trace_json = json.dumps(trace.model_dump())
    for phi in _SYNTHETIC_PHI:
        assert phi not in trace_json, f"PHI string found in trace: {phi!r}"


# ---------------------------------------------------------------------------
# emit_trace — structured log emission
# ---------------------------------------------------------------------------


def test_emit_trace_logs_at_info_level(caplog: pytest.LogCaptureFixture) -> None:
    trace = _make_trace()
    logger = logging.getLogger("clinical_copilot.chat")
    with caplog.at_level(logging.INFO, logger="clinical_copilot.chat"):
        emit_trace(trace, logger)
    assert any("encounter_trace" in r.getMessage() for r in caplog.records)


def test_emit_trace_includes_request_id_and_endpoint(caplog: pytest.LogCaptureFixture) -> None:
    trace = _make_trace(request_id="abc-999", endpoint="/v1/attach-and-extract")
    logger = logging.getLogger("clinical_copilot.chat")
    with caplog.at_level(logging.INFO, logger="clinical_copilot.chat"):
        emit_trace(trace, logger)
    combined = " ".join(r.getMessage() for r in caplog.records)
    assert "abc-999" in combined
    assert "/v1/attach-and-extract" in combined


def test_emit_trace_attaches_trace_extra(caplog: pytest.LogCaptureFixture) -> None:
    """emit_trace must attach the full trace dict as extra['trace']."""
    trace = _make_trace()
    logger = logging.getLogger("clinical_copilot.test_extra")
    with caplog.at_level(logging.INFO, logger="clinical_copilot.test_extra"):
        emit_trace(trace, logger)
    records_with_trace = [r for r in caplog.records if hasattr(r, "trace")]
    assert records_with_trace, "No log record had extra['trace'] set"
    attached = records_with_trace[0].trace  # type: ignore[attr-defined]
    for field in REQUIRED_FIELDS:
        assert field in attached


# ---------------------------------------------------------------------------
# PHI redaction filter applied during trace emission
# ---------------------------------------------------------------------------


def test_phi_filter_scrubs_phi_from_log_during_emit(caplog: pytest.LogCaptureFixture) -> None:
    """If PHI somehow ends up in a log message, the filter must redact it."""
    f = PHIRedactionFilter()
    phi_logger = logging.getLogger("clinical_copilot.phi_emit_test")
    phi_logger.addFilter(f)
    with caplog.at_level(logging.INFO, logger="clinical_copilot.phi_emit_test"):
        phi_logger.info("Patient SSN: 555-12-1234 DOB: 1980-01-01 admitted")
    assert "555-12-1234" not in caplog.text
    assert "1980-01-01" not in caplog.text
    assert "[REDACTED-SSN]" in caplog.text
    phi_logger.removeFilter(f)


# ---------------------------------------------------------------------------
# LangSmith payload visibility env-var wiring
# ---------------------------------------------------------------------------


def test_langsmith_inputs_outputs_visible_env_set_after_apply() -> None:
    from app.langsmith_env import apply_langchain_runtime_env
    from app.settings import Settings

    apply_langchain_runtime_env(Settings.load())
    assert os.environ.get("LANGCHAIN_HIDE_INPUTS") == "false"
    assert os.environ.get("LANGCHAIN_HIDE_OUTPUTS") == "false"
