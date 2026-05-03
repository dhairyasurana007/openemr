"""Product SLO ceilings (seconds) for UC1–UC7; keep aligned with ClinicalCopilotSloBudgets in OpenEMR PHP."""

from __future__ import annotations

# Keys must match OpenEMR ClinicalCopilotUseCase enum values (UC1…UC7).
USE_CASE_SLO_MAX_SECONDS: dict[str, int] = {
    "UC1": 20,
    "UC2": 5,
    "UC3": 8,
    "UC4": 8,
    "UC5": 10,
    "UC6": 15,
    "UC7": 15,
}

MAX_EXTRA_HTTP_SECONDS_OVER_SLO: float = 2.0
