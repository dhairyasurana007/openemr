"""Contract files for OpenEMR clinical-copilot retrieval tools (JSON syntax only)."""

from __future__ import annotations

import json
from pathlib import Path


def _schema_dir() -> Path:
    return Path(__file__).resolve().parent.parent / "schemas" / "retrieval"


def test_retrieval_response_schemas_are_valid_json() -> None:
    directory = _schema_dir()
    assert directory.is_dir(), f"missing schema directory: {directory}"
    for path in sorted(directory.glob("*.json")):
        raw = path.read_text(encoding="utf-8")
        data = json.loads(raw)
        assert isinstance(data, dict)
        assert "$schema" in data or "title" in data
