"""PHI redaction for app logs and LangSmith spans."""

from __future__ import annotations

import logging
import re

PHI_PATTERNS: list[tuple[str, re.Pattern[str]]] = [
    ("ssn",   re.compile(r"\b\d{3}-\d{2}-\d{4}\b")),
    ("dob",   re.compile(r"\b(19|20)\d{2}-\d{2}-\d{2}\b")),
    ("mrn",   re.compile(r"\bMRN[:\s]*[A-Za-z0-9-]+\b", re.I)),
    ("email", re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b")),
    ("phone", re.compile(r"\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b")),
]


def redact(text: str) -> str:
    """Replace PHI patterns with [REDACTED-<TYPE>] tokens."""
    for label, pattern in PHI_PATTERNS:
        text = pattern.sub(f"[REDACTED-{label.upper()}]", text)
    return text


class PHIRedactionFilter(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        record.msg = redact(str(record.msg))
        if record.args:
            record.args = tuple(redact(str(a)) for a in record.args)
        return True
