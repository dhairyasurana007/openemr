# Eval Gate Regression Rehearsal

Three diffs that each induce one rubric-category failure when applied.
Use them to verify the CI gate works before merging a real change.

## Usage

```bash
cd /path/to/openemr   # repo root

# Apply a diff and confirm the gate fails
git apply docker/clinical-copilot/copilot-agent/evals/regression_rehearsal/drop_citation.diff
cd docker/clinical-copilot/copilot-agent
python evals/run_evals.py   # expect non-zero exit on citation_present
cd ../../..
git checkout -- .            # revert all changes

# Repeat for the other diffs
git apply docker/clinical-copilot/copilot-agent/evals/regression_rehearsal/remove_refusal.diff
cd docker/clinical-copilot/copilot-agent
python evals/run_evals.py   # expect non-zero exit on safe_refusal
cd ../../..
git checkout -- .

git apply docker/clinical-copilot/copilot-agent/evals/regression_rehearsal/leak_ssn.diff
cd docker/clinical-copilot/copilot-agent
python evals/run_evals.py   # expect non-zero exit on no_phi_in_logs
cd ../../..
git checkout -- .
```

## What each diff tests

| Diff | File modified | Rubric that fails |
|------|--------------|-------------------|
| `drop_citation.diff` | `evals/rubrics.py` | `citation_present` — adds a `source_url` requirement that no golden-case stub satisfies |
| `remove_refusal.diff` | `evals/rubrics.py` | `safe_refusal` — removes the guard that excuses schedule-wide phrases inside explicit refusals |
| `leak_ssn.diff` | `app/log_redaction.py` | `no_phi_in_logs` — disables the `PHIRedactionFilter` so date-like strings (matching the DOB pattern) survive into captured logs |

## Rules

- **Never apply these diffs to `master` or `development`** — they are applied on-demand only.
- Refresh the diffs whenever C2–C7 changes land that touch the affected files,
  so they continue to apply cleanly against HEAD.
- The CI workflow (`clinical-copilot-agent.yml`) runs `python evals/run_evals.py`
  after pytest; any regression in the five rubric categories blocks merge.
