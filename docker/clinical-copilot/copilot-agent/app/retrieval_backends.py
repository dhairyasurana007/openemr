"""Pluggable backends for read-only retrieval tools (stub vs future OpenEMR REST)."""

from __future__ import annotations

import json
from typing import Any, Protocol, runtime_checkable


def _citation(tool: str, domain: str, path: str) -> dict[str, str]:
    return {
        "type": "openemr_rest",
        "tool": tool,
        "domain": domain,
        "method": "GET",
        "path": path,
    }


@runtime_checkable
class RetrievalBackend(Protocol):
    """Returns structured dicts matching ``schemas/retrieval/*.response.json`` shapes."""

    def list_schedule_slots(self, date: str, facility_id: str = "") -> dict[str, Any]:
        ...

    def get_patient_core_profile(self, patient_uuid: str) -> dict[str, Any]:
        ...

    def get_medication_list(self, patient_uuid: str) -> dict[str, Any]:
        ...

    def get_observations(self, patient_uuid: str) -> dict[str, Any]:
        ...

    def get_encounters_and_notes(self, patient_uuid: str) -> dict[str, Any]:
        ...

    def get_referrals_orders_care_gaps(self, patient_uuid: str) -> dict[str, Any]:
        ...


class StubRetrievalBackend:
    """Deterministic canned payloads for tests and offline agent wiring.

    Replace with HTTP-backed backend when OpenEMR REST tool paths are implemented.
    """

    def __init__(
        self,
        *,
        fail_tool: str | None = None,
        fail_detail: str = "simulated retrieval failure",
    ) -> None:
        self._fail_tool = fail_tool
        self._fail_detail = fail_detail

    def _status(self, tool: str) -> dict[str, Any] | None:
        if self._fail_tool == tool:
            return {"ok": False, "code": "stub_error", "detail": self._fail_detail}
        return None

    def list_schedule_slots(self, date: str, facility_id: str = "") -> dict[str, Any]:
        tool = "list_schedule_slots"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [_citation(tool, "schedule", "/api/facility/appointment")],
            "date": date,
            "slots": [
                {
                    "slot_id": "1",
                    "slot_uuid": "00000000-0000-4000-8000-000000000001",
                    "patient_uuid": "00000000-0000-4000-8000-0000000000aa",
                    "patient_display": "Demo, Dana",
                    "start_date": date,
                    "start_time": "09:00:00",
                    "end_time": "09:15:00",
                    "visit_type": "follow_up",
                    "status_code": "booked",
                    "facility_id": facility_id or "1",
                    "facility_uuid": "00000000-0000-4000-8000-0000000000f1",
                }
            ],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base

    def get_patient_core_profile(self, patient_uuid: str) -> dict[str, Any]:
        tool = "get_patient_core_profile"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [_citation(tool, "patient", f"/api/patient/{patient_uuid}")],
            "demographics": {
                "patient_uuid": patient_uuid,
                "pid": "1001",
                "first_name": "Dana",
                "last_name": "Demo",
                "DOB": "1975-04-01",
                "sex": "F",
            },
            "active_problems": [{"code": "E11.9", "description": "Type 2 diabetes mellitus"}],
            "allergies": [{"substance": "Penicillin", "reaction": "rash", "status": "active"}],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base

    def get_medication_list(self, patient_uuid: str) -> dict[str, Any]:
        tool = "get_medication_list"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [_citation(tool, "patient", f"/api/patient/{patient_uuid}/medication")],
            "medications": [
                {
                    "uuid": "00000000-0000-4000-8000-0000000000m1",
                    "drug": "Metformin",
                    "dosage": "500 mg",
                    "route": "oral",
                    "interval": "BID",
                    "active": True,
                    "start_date": "2024-01-10",
                    "end_date": "",
                }
            ],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base

    def get_observations(self, patient_uuid: str) -> dict[str, Any]:
        tool = "get_observations"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [_citation(tool, "fhir", f"/apis/default/fhir/Observation?patient={patient_uuid}")],
            "vitals": [
                {
                    "name": "Glucose",
                    "value": 118,
                    "unit": "mg/dL",
                    "effective_datetime": "2026-05-01T10:00:00Z",
                }
            ],
            "laboratory": [
                {
                    "name": "LDL",
                    "value": 145,
                    "unit": "mg/dL",
                    "effective_datetime": "2026-04-20T08:00:00Z",
                }
            ],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base

    def get_encounters_and_notes(self, patient_uuid: str) -> dict[str, Any]:
        tool = "get_encounters_and_notes"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [_citation(tool, "encounter", f"/api/patient/{patient_uuid}/encounter")],
            "encounters": [
                {
                    "encounter_uuid": "00000000-0000-4000-8000-0000000000e1",
                    "encounter_id": "501",
                    "date": "2026-05-01",
                    "reason": "Follow-up diabetes",
                    "facility": "Main Clinic",
                    "soap_notes": [
                        {
                            "section": "objective",
                            "text": "Point-of-care glucose 118 mg/dL.",
                        }
                    ],
                }
            ],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base

    def get_referrals_orders_care_gaps(self, patient_uuid: str) -> dict[str, Any]:
        tool = "get_referrals_orders_care_gaps"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [_citation(tool, "order", f"/api/patient/{patient_uuid}/order")],
            "referrals": [],
            "orders": [],
            "care_gaps": [],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base


def tool_payload_json(payload: dict[str, Any]) -> str:
    """Stable JSON string for ToolMessage content."""
    return json.dumps(payload, separators=(",", ":"), sort_keys=True)
