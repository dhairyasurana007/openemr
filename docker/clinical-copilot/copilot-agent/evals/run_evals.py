"""Offline eval runner for the Clinical Co-Pilot 50-case golden suite.

Usage:
    cd docker/clinical-copilot/copilot-agent
    python evals/run_evals.py

Exit codes:
    0 — all rubric categories pass thresholds
    1 — at least one rubric is below 80 % absolute or regressed >5 pp vs baseline
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

_EVALS_DIR = Path(__file__).parent

# Thresholds (from spec)
_MIN_ABSOLUTE = 0.80
_MAX_REGRESSION = 0.05


def _load_json(path: Path) -> object:
    with path.open(encoding="utf-8") as fh:
        return json.load(fh)


def main() -> int:
    # Ensure app package is importable when run from repo root or copilot-agent dir.
    import importlib.util
    import os

    copilot_root = _EVALS_DIR.parent
    if str(copilot_root) not in sys.path:
        sys.path.insert(0, str(copilot_root))

    from evals.rubrics import evaluate_case  # noqa: PLC0415

    cases: list[dict] = _load_json(_EVALS_DIR / "golden_cases.json")  # type: ignore[assignment]
    baseline: dict[str, float] = _load_json(_EVALS_DIR / "baseline.json")  # type: ignore[assignment]

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

        if rate < _MIN_ABSOLUTE:
            status = f"FAIL <{_MIN_ABSOLUTE:.0%} ({n_pass}/{n_cases})"
            failed = True
        elif delta < -_MAX_REGRESSION:
            status = f"FAIL regression {delta:+.1%} ({n_pass}/{n_cases})"
            failed = True
        else:
            status = f"PASS ({n_pass}/{n_cases})"

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
        if rate < _MIN_ABSOLUTE:
            status = f"FAIL <{_MIN_ABSOLUTE:.0%} ({n_pass}/{n_cases})"
        elif delta < -_MAX_REGRESSION:
            status = f"FAIL regression {delta:+.1%} ({n_pass}/{n_cases})"
        else:
            status = f"PASS ({n_pass}/{n_cases})"
        print(f"| {rubric} | {rate:.1%} | {base:.1%} | {delta:+.1%} | {status} |")

    print()
    print(header)
    print(separator)
    for row in rows:
        print(row)
    print()
    print(f"Total cases: {len(cases)}")
    print(f"Rubrics evaluated: {', '.join(sorted(rates))}")
    print()

    if failed:
        print("EVAL GATE: FAILED — one or more rubrics below threshold.")
        return 1

    print("EVAL GATE: PASSED — all rubrics above threshold.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
