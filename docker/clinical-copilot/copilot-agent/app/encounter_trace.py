"""Per-encounter structured trace emitted once per request (PDF required fields)."""

from __future__ import annotations

import logging

from pydantic import BaseModel


class EncounterTrace(BaseModel):
    request_id: str
    endpoint: str
    tool_sequence: list[str]
    step_latency_ms: dict[str, int]
    token_usage: dict[str, int]
    cost_estimate_usd: float
    retrieval_hits: int
    extraction_confidence: float | None
    eval_outcome: str | None
    phi_redacted: bool = True


def emit_trace(trace: EncounterTrace, logger: logging.Logger) -> None:
    """Emit the encounter trace as a structured log record."""
    logger.info(
        "encounter_trace request_id=%s endpoint=%s",
        trace.request_id,
        trace.endpoint,
        extra={"trace": trace.model_dump()},
    )
