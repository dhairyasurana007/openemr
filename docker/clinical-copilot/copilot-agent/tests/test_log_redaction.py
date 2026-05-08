"""Tests for PHI redaction utilities."""

from __future__ import annotations

import logging

import pytest

from app.log_redaction import PHIRedactionFilter, PHI_PATTERNS, redact


# ---------------------------------------------------------------------------
# redact() — pattern coverage
# ---------------------------------------------------------------------------


def test_ssn_is_redacted() -> None:
    result = redact("Patient SSN: 555-12-1234")
    assert "555-12-1234" not in result
    assert "[REDACTED-SSN]" in result


def test_dob_is_redacted() -> None:
    result = redact("Date of birth: 1980-01-01")
    assert "1980-01-01" not in result
    assert "[REDACTED-DOB]" in result


def test_mrn_is_redacted() -> None:
    result = redact("MRN: demo-001 admitted today")
    assert "demo-001" not in result
    assert "[REDACTED-MRN]" in result


def test_mrn_colon_variant_is_redacted() -> None:
    result = redact("MRN:ABC123 on file")
    assert "ABC123" not in result
    assert "[REDACTED-MRN]" in result


def test_email_is_redacted() -> None:
    result = redact("Contact: patient@hospital.org for follow-up")
    assert "patient@hospital.org" not in result
    assert "[REDACTED-EMAIL]" in result


def test_phone_dash_is_redacted() -> None:
    result = redact("Call 555-123-4567 for appointment")
    assert "555-123-4567" not in result
    assert "[REDACTED-PHONE]" in result


def test_phone_dot_is_redacted() -> None:
    result = redact("Phone: 555.123.4567")
    assert "555.123.4567" not in result
    assert "[REDACTED-PHONE]" in result


def test_non_phi_text_unchanged() -> None:
    text = "Glucose: 5.2 mg/dL (reference range 3.5-5.0)"
    assert redact(text) == text


def test_multiple_phi_types_all_redacted() -> None:
    text = "SSN 555-12-1234 DOB 1980-01-01 MRN demo-001"
    result = redact(text)
    assert "555-12-1234" not in result
    assert "1980-01-01" not in result
    assert "demo-001" not in result


def test_redaction_is_idempotent() -> None:
    text = "SSN: 555-12-1234"
    once = redact(text)
    twice = redact(once)
    assert once == twice


def test_empty_string_unchanged() -> None:
    assert redact("") == ""


# ---------------------------------------------------------------------------
# PHIRedactionFilter — logging integration
# ---------------------------------------------------------------------------


def test_filter_redacts_msg() -> None:
    f = PHIRedactionFilter()
    record = logging.LogRecord(
        name="test", level=logging.INFO, pathname="", lineno=0,
        msg="SSN: 555-12-1234", args=(), exc_info=None,
    )
    f.filter(record)
    assert "555-12-1234" not in record.msg
    assert "[REDACTED-SSN]" in record.msg


def test_filter_redacts_args() -> None:
    f = PHIRedactionFilter()
    record = logging.LogRecord(
        name="test", level=logging.INFO, pathname="", lineno=0,
        msg="Patient info: %s",
        args=("MRN: demo-001",),
        exc_info=None,
    )
    f.filter(record)
    assert isinstance(record.args, tuple)
    assert "demo-001" not in record.args[0]
    assert "[REDACTED-MRN]" in record.args[0]


def test_filter_allows_record_through() -> None:
    """filter() must return True so the record is not suppressed."""
    f = PHIRedactionFilter()
    record = logging.LogRecord(
        name="test", level=logging.INFO, pathname="", lineno=0,
        msg="normal log message", args=(), exc_info=None,
    )
    assert f.filter(record) is True


def test_filter_on_root_logger_via_caplog(caplog: pytest.LogCaptureFixture) -> None:
    f = PHIRedactionFilter()
    test_logger = logging.getLogger("phi_test_logger")
    test_logger.addFilter(f)
    with caplog.at_level(logging.INFO, logger="phi_test_logger"):
        test_logger.info("DOB: 1980-01-01 admitted")
    assert "1980-01-01" not in caplog.text
    assert "[REDACTED-DOB]" in caplog.text
    test_logger.removeFilter(f)
