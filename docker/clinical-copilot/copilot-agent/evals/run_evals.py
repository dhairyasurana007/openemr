"""Offline eval runner for the Clinical Co-Pilot 50-case golden suite.

Usage:
    cd docker/clinical-copilot/copilot-agent
    python evals/run_evals.py

Exit codes:
    0 - all rubric categories pass thresholds
    1 - at least one rubric is below threshold or regressed beyond max delta
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

_EVALS_DIR = Path(__file__).parent

_DEFAULT_GATE_CONFIG = {
    "min_absolute_rate": 0.80,
    "max_regression_delta": 0.05,
}


def _load_json(path: Path) -> object:
    with path.open(encoding="utf-8") as fh:
        return json.load(fh)


def _load_gate_config() -> dict[str, float]:
    configured = _load_json(_EVALS_DIR / "gate_config.json")
    if not isinstance(configured, dict):
        return dict(_DEFAULT_GATE_CONFIG)

    min_absolute_rate = configured.get("min_absolute_rate", _DEFAULT_GATE_CONFIG["min_absolute_rate"])
    max_regression_delta = configured.get("max_regression_delta", _DEFAULT_GATE_CONFIG["max_regression_delta"])
    if not isinstance(min_absolute_rate, (int, float)) or not isinstance(max_regression_delta, (int, float)):
        return dict(_DEFAULT_GATE_CONFIG)

    return {
        "min_absolute_rate": float(min_absolute_rate),
        "max_regression_delta": float(max_regression_delta),
    }


def _compute_status(
    *,
    rate: float,
    baseline: float,
    pass_count: int,
    total_count: int,
    min_absolute_rate: float,
    max_regression_delta: float,
) -> tuple[str, bool]:
    delta = rate - baseline
    if rate < min_absolute_rate:
        return (f"FAIL <{min_absolute_rate:.0%} ({pass_count}/{total_count})", True)
    if delta < -max_regression_delta:
        return (f"FAIL regression {delta:+.1%} ({pass_count}/{total_count})", True)
    return (f"PASS ({pass_count}/{total_count})", False)


def main() -> int:
    # Ensure app package is importable when run from repo root or copilot-agent dir.
    copilot_root = _EVALS_DIR.parent
    if str(copilot_root) not in sys.path:
        sys.path.insert(0, str(copilot_root))

    from evals.rubrics import evaluate_case  # noqa: PLC0415

    cases: list[dict] = _load_json(_EVALS_DIR / "golden_cases.json")  # type: ignore[assignment]
    baseline: dict[str, float] = _load_json(_EVALS_DIR / "baseline.json")  # type: ignore[assignment]

    gate_config = _load_gate_config()
    min_absolute_rate = gate_config["min_absolute_rate"]
    max_regression_delta = gate_config["max_regression_delta"]

    # Accumulate per-rubric results across all cases.
    rubric_outcomes: dict[str, list[bool]] = {}

    for case in cases:
        case_results = evaluate_case(case)
        for rubric, val in case_results.items():
            if val is None:
                continue
            rubric_outcomes.setdefault(rubric, []).append(val)

    # Compute pass rates.
    rates: dict[str, float] = {
        rubric: sum(vals) / len(vals)
        for rubric, vals in rubric_outcomes.items()
        if vals
    }

    # Build report rows.
    header = f"{'rubric':<26} {'rate':>6} {'baseline':>9} {'delta':>7}  status"
    separator = "-" * len(header)

    rows: list[str] = []
    failed = False

    for rubric in sorted(rates):
        rate = rates[rubric]
        base = baseline.get(rubric, 0.0)
        delta = rate - base
        n_cases = len(rubric_outcomes[rubric])
        n_pass = sum(rubric_outcomes[rubric])

        status, is_failed = _compute_status(
            rate=rate,
            baseline=base,
            pass_count=n_pass,
            total_count=n_cases,
            min_absolute_rate=min_absolute_rate,
            max_regression_delta=max_regression_delta,
        )
        failed = failed or is_failed

        rows.append(
            f"{rubric:<26} {rate:>6.1%} {base:>9.1%} {delta:>+7.1%}  {status}"
        )

    # Print Markdown-friendly table.
    print()
    print("| rubric | rate | baseline | delta | status |")
    print("|--------|------|----------|-------|--------|")
    for rubric in sorted(rates):
        rate = rates[rubric]
        base = baseline.get(rubric, 0.0)
        delta = rate - base
        n_cases = len(rubric_outcomes[rubric])
        n_pass = sum(rubric_outcomes[rubric])
        status, _ = _compute_status(
            rate=rate,
            baseline=base,
            pass_count=n_pass,
            total_count=n_cases,
            min_absolute_rate=min_absolute_rate,
            max_regression_delta=max_regression_delta,
        )
        print(f"| {rubric} | {rate:.1%} | {base:.1%} | {delta:+.1%} | {status} |")

    print()
    print(header)
    print(separator)
    for row in rows:
        print(row)
    print()
    print(f"Total cases: {len(cases)}")
    print(f"Rubrics evaluated: {', '.join(sorted(rates))}")
    print(
        "Gate config: "
        f"min_absolute_rate={min_absolute_rate:.0%}, "
        f"max_regression_delta={max_regression_delta:.1%}"
    )
    print()

    if failed:
        print("EVAL GATE: FAILED - one or more rubrics below threshold.")
        return 1

    print("EVAL GATE: PASSED - all rubrics above threshold.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
