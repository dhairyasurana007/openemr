"""In-memory per-request progress snapshots for live UI polling."""

from __future__ import annotations

import threading
import time
from typing import Any

_LOCK = threading.Lock()
_PROGRESS: dict[str, dict[str, Any]] = {}
_TTL_SECONDS = 15 * 60


def _prune(now: float) -> None:
    stale = [rid for rid, item in _PROGRESS.items() if (now - float(item.get("updated_at", 0.0))) > _TTL_SECONDS]
    for rid in stale:
        _PROGRESS.pop(rid, None)


def set_progress(
    request_id: str,
    *,
    phase: str,
    worker: str | None = None,
    detail: str | None = None,
    meta: dict[str, Any] | None = None,
) -> None:
    if request_id == "":
        return
    now = time.time()
    with _LOCK:
        _prune(now)
        _PROGRESS[request_id] = {
            "request_id": request_id,
            "phase": phase,
            "worker": worker or "",
            "detail": detail or "",
            "meta": dict(meta or {}),
            "updated_at": now,
        }


def get_progress(request_id: str) -> dict[str, Any] | None:
    if request_id == "":
        return None
    now = time.time()
    with _LOCK:
        _prune(now)
        item = _PROGRESS.get(request_id)
        if item is None:
            return None
        return dict(item)
