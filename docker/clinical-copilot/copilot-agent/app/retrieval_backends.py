"""Pluggable backends for read-only retrieval tools (stub vs future OpenEMR REST)."""

from __future__ import annotations

import json
from typing import Any, Protocol, runtime_checkable


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

    def get_calendar(
        self,
        start_date: str,
        end_date: str = "",
        calendar_id: str = "",
        facility_id: str = "",
    ) -> dict[str, Any]:
        ...


class StubRetrievalBackend:
    """Shape-only backend: valid JSON shells with **no chart rows** until OpenEMR REST is wired.

    Only identifiers passed into tool arguments (e.g. ``patient_uuid``, ``date``) are echoed
    where the schema requires a value; all clinical content arrays are empty. No demo patients,
    vitals, labs, or narrative is invented here.

    ``fail_tool`` simulates a tool error for tests that need a failure path.
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
            "citations": [],
            "date": date,
            "slots": [],
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
            "citations": [],
            "demographics": {
                "patient_uuid": patient_uuid,
                "pid": "",
                "first_name": "",
                "last_name": "",
                "DOB": "",
                "sex": "",
            },
            "active_problems": [],
            "allergies": [],
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
            "citations": [],
            "medications": [],
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
            "citations": [],
            "vitals": [],
            "laboratory": [],
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
            "citations": [],
            "encounters": [],
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
            "citations": [],
            "referrals": [],
            "orders": [],
            "care_gaps": [],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base

    def get_calendar(
        self,
        start_date: str,
        end_date: str = "",
        calendar_id: str = "",
        facility_id: str = "",
    ) -> dict[str, Any]:
        tool = "get_calendar"
        base: dict[str, Any] = {
            "tool": tool,
            "schema_version": "1",
            "citations": [],
            "query": {
                "start_date": start_date,
                "end_date": end_date,
                "calendar_id": calendar_id,
                "facility_id": facility_id,
            },
            "calendars": [],
            "events": [],
        }
        st = self._status(tool)
        if st:
            base["retrieval_status"] = st
        return base


def tool_payload_json(payload: dict[str, Any]) -> str:
    """Stable JSON string for ToolMessage content."""
    return json.dumps(payload, separators=(",", ":"), sort_keys=True)
