"""Simple access logging middleware for copilot-agent HTTP requests."""

from __future__ import annotations

import logging
import time

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import Response


class AccessLogMiddleware(BaseHTTPMiddleware):
    """Logs method/path/status/latency for every request."""

    def __init__(self, app) -> None:  # type: ignore[no-untyped-def]
        super().__init__(app)
        self._log = logging.getLogger("clinical_copilot.access")

    async def dispatch(self, request: Request, call_next) -> Response:  # type: ignore[no-untyped-def]
        start = time.perf_counter()
        request_id = (request.headers.get("X-Request-Id") or "").strip()
        if request_id == "":
            request_id = f"agent-{int(time.time() * 1000)}"
        try:
            response = await call_next(request)
        except Exception:
            self._log.exception(
                "clinical_copilot_access_error request_id=%s method=%s path=%s total_ms=%d",
                request_id,
                request.method,
                request.url.path,
                int((time.perf_counter() - start) * 1000.0),
            )
            raise

        self._log.info(
            "clinical_copilot_access request_id=%s method=%s path=%s status=%d total_ms=%d",
            request_id,
            request.method,
            request.url.path,
            response.status_code,
            int((time.perf_counter() - start) * 1000.0),
        )
        return response

