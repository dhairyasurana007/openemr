"""Optional concurrency cap for non-health traffic (inbound backpressure)."""

from __future__ import annotations

import asyncio

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response


def _is_health_path(path: str) -> bool:
    if path == "/health":
        return True
    if path.startswith("/meta/health/"):
        return True
    if path in ("/openapi.json", "/docs", "/redoc"):
        return True
    return False


class InflightLimitMiddleware(BaseHTTPMiddleware):
    """Return 503 when ``max_inflight`` non-health requests are already running."""

    def __init__(self, app: object, max_inflight: int) -> None:
        super().__init__(app)
        self._max = max_inflight
        self._active = 0
        self._gate = asyncio.Lock()

    async def dispatch(self, request: Request, call_next: object) -> Response:
        if self._max <= 0 or _is_health_path(request.url.path):
            return await call_next(request)  # type: ignore[no-any-return,misc]

        async with self._gate:
            if self._active >= self._max:
                return JSONResponse(
                    status_code=503,
                    content={
                        "detail": "Server busy; retry shortly.",
                        "type": "inflight_limit",
                    },
                )
            self._active += 1

        try:
            return await call_next(request)  # type: ignore[no-any-return,misc]
        finally:
            async with self._gate:
                self._active -= 1
