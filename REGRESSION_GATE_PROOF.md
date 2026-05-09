# Regression Gate Proof (Commit 1)

This document defines reproducible proof artifacts for the offline 50-case eval gate.

## Baseline pass proof

Run from `docker/clinical-copilot/copilot-agent`:

```bash
python evals/run_evals.py
python -m pytest tests/test_evals_offline.py -v
```

Required baseline evidence:
- `run_evals.py` exits with code `0`.
- Output includes `Total cases: 50`.
- Output includes `EVAL GATE: PASSED`.
- `pytest` run passes with no failures in `tests/test_evals_offline.py`.

## Rehearsal regression fail proof

Use one rehearsal diff from `evals/regression_rehearsal/` (for example `drop_citation.diff`), then run:

```bash
python evals/run_evals.py
```

Required fail evidence:
- Exit code is `1`.
- Output includes `EVAL GATE: FAILED`.
- The targeted rubric row reports `FAIL`.

Revert by applying the same diff with `git apply -R`.

## Gate thresholds

Thresholds are explicitly versioned in `evals/gate_config.json`:
- `min_absolute_rate = 0.80`
- `max_regression_delta = 0.05`

The gate fails when either condition is true:
- Absolute rubric pass rate is below `min_absolute_rate`.
- Rubric rate regresses by more than `max_regression_delta` versus `evals/baseline.json`.
