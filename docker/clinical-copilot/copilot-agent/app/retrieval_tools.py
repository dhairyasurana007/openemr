"""LangChain-bound read-only retrieval tools (JSON-shaped payloads)."""

from __future__ import annotations

import json
from collections.abc import Callable
from typing import Any

from langchain_core.tools import StructuredTool
from pydantic import BaseModel, Field

from app.retrieval_backends import RetrievalBackend, tool_payload_json


class ListScheduleSlotsArgs(BaseModel):
    """Schedule- or list-scoped day column (UC1 / UC6 / UC7 seeds)."""

    date: str = Field(description="ISO date YYYY-MM-DD for the schedule column.")
    facility_id: str = Field(default="", description="Optional facility identifier filter.")


class PatientScopedArgs(BaseModel):
    """Encounter-scoped patient identifier for chart reads."""

    patient_uuid: str = Field(description="Patient UUID scoped by the caller authorization.")


class FindPatientCandidatesArgs(BaseModel):
    """Name-fragment based patient lookup to resolve UUID before chart reads."""

    name: str = Field(description="Patient name fragment (or MRN/pid fragment) to search.")
    limit: int = Field(default=5, ge=1, le=10, description="Max candidates to return (1-10).")


class GetCalendarArgs(BaseModel):
    """Date-bounded calendar read (calendars metadata + events)."""

    start_date: str = Field(description="ISO date YYYY-MM-DD start of the window (inclusive).")
    end_date: str = Field(
        default="",
        description="ISO date YYYY-MM-DD end of the window (inclusive); empty means same as start_date.",
    )
    calendar_id: str = Field(default="", description="Optional calendar identifier filter.")
    facility_id: str = Field(default="", description="Optional facility identifier filter.")


def build_retrieval_tools(backend: RetrievalBackend) -> list[StructuredTool]:
    """Create tools whose **model** chooses names/args; execution is delegated to ``backend``."""

    def _wrap(name: str, fn: Callable[..., dict[str, Any]]) -> Callable[..., str]:
        def _inner(*args: Any, **kwargs: Any) -> str:
            payload = fn(*args, **kwargs)
            return tool_payload_json(payload)

        _inner.__name__ = name
        return _inner

    tools: list[StructuredTool] = [
        StructuredTool.from_function(
            name="list_schedule_slots",
            description=(
                "Read-only: list schedule slots for one day with stable slot rows "
                "(ids, times, patient display, visit type, status). Use for day-wide scans."
            ),
            args_schema=ListScheduleSlotsArgs,
            func=_wrap(
                "list_schedule_slots",
                lambda date, facility_id="": backend.list_schedule_slots(date=date, facility_id=facility_id),
            ),
        ),
        StructuredTool.from_function(
            name="get_calendar",
            description=(
                "Read-only: OpenEMR calendar context for a date window—calendars visible in scope "
                "and events (times, titles, statuses as returned by the backend). Use for calendar "
                "views, blocks, and non-slot calendar questions; combine with list_schedule_slots when "
                "appointment slots are needed."
            ),
            args_schema=GetCalendarArgs,
            func=_wrap(
                "get_calendar",
                lambda start_date, end_date="", calendar_id="", facility_id="": backend.get_calendar(
                    start_date,
                    end_date=end_date,
                    calendar_id=calendar_id,
                    facility_id=facility_id,
                ),
            ),
        ),
        StructuredTool.from_function(
            name="find_patient_candidates",
            description=(
                "Read-only: resolve patient identity by name/MRN fragment when patient_uuid is unknown. "
                "Returns candidate patient_uuid values for follow-up chart tools."
            ),
            args_schema=FindPatientCandidatesArgs,
            func=_wrap(
                "find_patient_candidates",
                lambda name, limit=5: backend.find_patient_candidates(name=name, limit=limit),
            ),
        ),
        StructuredTool.from_function(
            name="get_patient_core_profile",
            description=(
                "Read-only: demographics, active problems, allergies for one patient "
                "(fixed keys for verification)."
            ),
            args_schema=PatientScopedArgs,
            func=_wrap(
                "get_patient_core_profile",
                lambda patient_uuid: backend.get_patient_core_profile(patient_uuid),
            ),
        ),
        StructuredTool.from_function(
            name="get_medication_list",
            description="Read-only: active medication rows (drug, dose, route, interval, dates).",
            args_schema=PatientScopedArgs,
            func=_wrap("get_medication_list", lambda patient_uuid: backend.get_medication_list(patient_uuid)),
        ),
        StructuredTool.from_function(
            name="get_observations",
            description="Read-only: vitals and laboratory numeric rows as structured arrays.",
            args_schema=PatientScopedArgs,
            func=_wrap("get_observations", lambda patient_uuid: backend.get_observations(patient_uuid)),
        ),
        StructuredTool.from_function(
            name="get_encounters_and_notes",
            description="Read-only: encounters with SOAP note snippets for grounding visit documentation.",
            args_schema=PatientScopedArgs,
            func=_wrap(
                "get_encounters_and_notes",
                lambda patient_uuid: backend.get_encounters_and_notes(patient_uuid),
            ),
        ),
        StructuredTool.from_function(
            name="get_referrals_orders_care_gaps",
            description="Read-only: referrals, orders, and care-gap rows (may be empty arrays).",
            args_schema=PatientScopedArgs,
            func=_wrap(
                "get_referrals_orders_care_gaps",
                lambda patient_uuid: backend.get_referrals_orders_care_gaps(patient_uuid),
            ),
        ),
    ]
    return tools


def parse_tool_json_content(content: str) -> dict[str, Any] | None:
    """Best-effort parse of tool return JSON."""
    try:
        data = json.loads(content)
    except (TypeError, ValueError):
        return None
    return data if isinstance(data, dict) else None
