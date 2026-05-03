"""BAA / PHI posture: LangSmith-related settings are keys and flags only (no chart payload fields)."""

from __future__ import annotations

import dataclasses

from app.settings import Settings


def test_settings_dataclass_has_no_patient_or_encounter_fields() -> None:
    for f in dataclasses.fields(Settings):
        name = f.name.lower()
        assert "patient" not in name
        assert "encounter" not in name
        assert "mrn" not in name
