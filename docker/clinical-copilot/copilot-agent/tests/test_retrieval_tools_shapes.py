"""Stub backend payloads align with required schema keys (syntax-level contract)."""

from __future__ import annotations

from app.retrieval_backends import StubRetrievalBackend


def _assert_keys(payload: dict[str, object], required: set[str]) -> None:
    missing = required - payload.keys()
    assert not missing, f"missing keys {missing} in {payload.get('tool')}"


def test_stub_payloads_include_contract_keys() -> None:
    b = StubRetrievalBackend()
    obs = b.get_observations("u1")
    _assert_keys(
        obs,
        {"tool", "schema_version", "citations", "vitals", "laboratory"},
    )
    prof = b.get_patient_core_profile("u1")
    _assert_keys(
        prof,
        {"tool", "schema_version", "citations", "demographics", "active_problems", "allergies"},
    )
    meds = b.get_medication_list("u1")
    _assert_keys(meds, {"tool", "schema_version", "citations", "medications"})
    enc = b.get_encounters_and_notes("u1")
    _assert_keys(enc, {"tool", "schema_version", "citations", "encounters"})
    ref = b.get_referrals_orders_care_gaps("u1")
    _assert_keys(ref, {"tool", "schema_version", "citations", "referrals", "orders", "care_gaps"})
    slots = b.list_schedule_slots("2026-05-01")
    _assert_keys(slots, {"tool", "schema_version", "citations", "date", "slots"})
