"""Pilot gate: UC SLO ceilings stay aligned with OpenEMR ClinicalCopilotSloBudgets (see app/slo_budgets.py)."""

from __future__ import annotations

from app.slo_budgets import MAX_EXTRA_HTTP_SECONDS_OVER_SLO, USE_CASE_SLO_MAX_SECONDS


def test_all_seven_use_cases_have_slo() -> None:
    expected = {f"UC{i}" for i in range(1, 8)}
    assert set(USE_CASE_SLO_MAX_SECONDS.keys()) == expected


def test_slo_values_are_positive_integers() -> None:
    for uc, sec in USE_CASE_SLO_MAX_SECONDS.items():
        assert isinstance(sec, int), uc
        assert sec > 0, uc


def test_http_margin_constant() -> None:
    assert MAX_EXTRA_HTTP_SECONDS_OVER_SLO == 2.0
