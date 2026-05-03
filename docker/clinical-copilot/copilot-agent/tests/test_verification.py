"""Unit tests for post-tool verification (grounding + failure transparency)."""

from __future__ import annotations

from app.verification import (
    apply_failure_transparency_footer,
    verify_clinical_quantities_grounded,
    verify_patient_chart_request_used_tools,
    verify_tool_failures_disclosed,
)


def test_grounding_passes_when_quantity_in_tool_json() -> None:
    source = '{"laboratory":[{"value":145,"unit":"mg/dL"}]}'
    text = "LDL was reported as 145 mg/dL."
    assert not verify_clinical_quantities_grounded(text, source)


def test_grounding_flags_invented_mg_dl() -> None:
    source = '{"laboratory":[{"value":120,"unit":"mg/dL"}]}'
    text = "LDL was 145 mg/dL on the last draw."
    findings = verify_clinical_quantities_grounded(text, source)
    assert any(f.code == "ungrounded_clinical_quantity" for f in findings)


def test_tool_failure_must_be_disclosed_or_footer_added() -> None:
    payloads = [
        {
            "tool": "get_observations",
            "vitals": [],
            "laboratory": [],
            "retrieval_status": {"ok": False, "code": "x", "detail": "down"},
        }
    ]
    silent = "Glucose is fine today."
    assert verify_tool_failures_disclosed(silent, payloads)
    fixed = apply_failure_transparency_footer(silent, payloads)
    assert not verify_tool_failures_disclosed(fixed, payloads)


def test_patient_chart_message_requires_patient_scoped_tool() -> None:
    msg = "What are the latest labs for this patient?"
    tools_used: list[dict[str, str]] = [{"name": "list_schedule_slots", "args": {}, "status": "ok"}]
    findings = verify_patient_chart_request_used_tools(msg, tools_used)
    assert any(f.code == "patient_chart_tools_missing" for f in findings)


def test_patient_chart_message_passes_when_patient_tool_ran() -> None:
    msg = "Show vitals for the patient."
    tools_used = [{"name": "get_observations", "args": {"patient_uuid": "u1"}, "status": "ok"}]
    assert not verify_patient_chart_request_used_tools(msg, tools_used)


def test_schedule_primary_without_patient_chart_phrase_skips_patient_tool_rule() -> None:
    msg = "What is on my schedule for today?"
    tools_used: list[dict[str, str]] = [{"name": "list_schedule_slots", "args": {"date": "2026-05-01"}, "status": "ok"}]
    assert not verify_patient_chart_request_used_tools(msg, tools_used)


def test_tool_failure_disclosure_in_text_passes() -> None:
    payloads = [{"tool": "get_observations", "retrieval_status": {"ok": False}}]
    text = "The chart lookup failed; I cannot confirm a glucose value."
    assert not verify_tool_failures_disclosed(text, payloads)
