"""Post-tool verification: numeric grounding, tool-failure transparency, partials."""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from typing import Any

# Clinical quantities in the assistant reply must be supported by numeric + unit evidence in tool JSON.
_CLINICAL_QUANTITY = re.compile(
    r"\b(\d+(?:\.\d+)?)\s*((?:mg/dL|mg/dl|mmHg|bpm|mcg|mg\b|g\b|%))\b",
    re.IGNORECASE,
)

# Patient / chart questions should invoke at least one patient-scoped retrieval tool.
_REQUIRES_PATIENT_SCOPED_TOOLS = re.compile(
    r"\b("
    r"this\s+patient|the\s+patient|patient\x27s|patient\s+chart|about\s+the\s+patient|for\s+this\s+patient|for\s+the\s+patient|"
    r"patient\x27s\s+(labs?|vitals?|meds?|medications?|allerg|history|chart|notes?)|"
    r"chart\s+(for|on|about)|labs?\s+(for|on|from)|lab\s+results?|"
    r"vitals?\s+(for|on)?|medications?\s+(for|on)?|meds?\s+(for|on)?|"
    r"allerg(y|ies)\s+(for|on)?|problem\s+list|encounter\s+notes?|"
    r"what\s+(is|are)\s+.{0,40}\s+(on\s+)?(file|record|chart)\b"
    r")\b",
    re.IGNORECASE,
)

_SCHEDULE_COLUMN_PRIMARY = re.compile(
    r"\b("
    r"day\x27?s\s+schedule|schedule\s+for|my\s+schedule|morning\s+column|afternoon\s+column|"
    r"clinic\s+list\s+for\s+the\s+day|appointment(s)?\s+today|list\s+of\s+slots|"
    r"who\s+is\s+on\s+my\s+schedule"
    r")\b",
    re.IGNORECASE,
)

_PATIENT_SCOPED_TOOL_NAMES = frozenset(
    {
        "get_patient_core_profile",
        "get_medication_list",
        "get_observations",
        "get_encounters_and_notes",
        "get_referrals_orders_care_gaps",
    }
)

_FAILURE_DISCLOSURE = re.compile(
    r"\b("
    r"failed|failure|unavailable|not\s+available|"
    r"could\s+not|couldn\x27t|can\x27t\s+retrieve|unable\s+to\s+retrieve|"
    r"retrieval\s+error|chart\s+lookup\s+failed|no\s+data\s+returned|"
    r"tool\s+(returned\s+an\s+)?error"
    r")\b",
    re.IGNORECASE,
)


@dataclass(frozen=True)
class VerificationFinding:
    code: str
    detail: str


def _retrieval_failed(payload: dict[str, Any]) -> bool:
    rs = payload.get("retrieval_status")
    if not isinstance(rs, dict):
        return False
    ok = rs.get("ok")
    return ok is False


def aggregate_tool_source_text(tool_payloads: list[dict[str, Any]]) -> str:
    """Flatten tool dicts for substring grounding checks."""
    parts: list[str] = []
    for p in tool_payloads:
        try:
            parts.append(json.dumps(p, sort_keys=True))
        except (TypeError, ValueError):
            parts.append(str(p))
    return "\n".join(parts)


def verify_clinical_quantities_grounded(assistant_text: str, source_text: str) -> list[VerificationFinding]:
    """Clinical quantities in the reply must be supported by tool JSON (value + unit co-present).

    Structured tool payloads often split numbers and units across JSON keys, so we require the
    numeric token and the unit substring to both appear in the serialized source text.
    """
    findings: list[VerificationFinding] = []
    lowered_source = source_text.casefold()
    for m in _CLINICAL_QUANTITY.finditer(assistant_text):
        raw_num, raw_unit = m.group(1), m.group(2)
        num_pat = re.compile(rf"(?<![0-9.]){re.escape(raw_num)}(?![0-9.])")
        unit_piece = raw_unit.casefold().replace(" ", "")
        src_compact = lowered_source.replace(" ", "").replace('"', "").replace("'", "")
        if num_pat.search(source_text) is None:
            findings.append(
                VerificationFinding(
                    code="ungrounded_clinical_quantity",
                    detail=f"reply cites {m.group(0)!r} but numeric token {raw_num!r} is absent from tool payloads",
                )
            )
            continue
        if unit_piece not in src_compact:
            findings.append(
                VerificationFinding(
                    code="ungrounded_clinical_quantity",
                    detail=f"reply cites {m.group(0)!r} but unit {raw_unit!r} is absent from tool payloads",
                )
            )
    return findings


def verify_tool_failures_disclosed(assistant_text: str, tool_payloads: list[dict[str, Any]]) -> list[VerificationFinding]:
    """If any tool reports retrieval_status.ok=false, reply should disclose failure."""
    any_failed = any(_retrieval_failed(p) for p in tool_payloads)
    if not any_failed:
        return []
    if _FAILURE_DISCLOSURE.search(assistant_text):
        return []
    return [
        VerificationFinding(
            code="tool_failure_not_disclosed",
            detail="one or more tools returned retrieval_status.ok=false but reply omits a failure notice",
        )
    ]


def verify_patient_chart_request_used_tools(
    user_message: str, tools_used: list[dict[str, Any]]
) -> list[VerificationFinding]:
    """If the user message reads like a patient-chart factual request, require a patient-scoped tool call."""
    text = user_message.strip()
    if not text:
        return []
    if _SCHEDULE_COLUMN_PRIMARY.search(text) and not _REQUIRES_PATIENT_SCOPED_TOOLS.search(text):
        return []
    if not _REQUIRES_PATIENT_SCOPED_TOOLS.search(text):
        return []
    names = {str(t.get("name", "")) for t in tools_used if isinstance(t, dict)}
    if names & _PATIENT_SCOPED_TOOL_NAMES:
        return []
    return [
        VerificationFinding(
            code="patient_chart_tools_missing",
            detail="message appears to request patient chart facts but no patient-scoped retrieval tool was executed",
        )
    ]


def apply_failure_transparency_footer(assistant_text: str, tool_payloads: list[dict[str, Any]]) -> str:
    """Append a neutral disclosure when tools failed and the model did not mention it."""
    findings = verify_tool_failures_disclosed(assistant_text, tool_payloads)
    if not findings:
        return assistant_text
    footer = (
        "\n\nChart lookup note: at least one read-only tool returned an error from OpenEMR "
        "(or the stub backend). Treat clinical quantities above as unverified against the chart "
        "until retrieval succeeds."
    )
    return assistant_text.rstrip() + footer
