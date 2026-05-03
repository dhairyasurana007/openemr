"""Shared httpx client and concurrency gate for agent→OpenEMR REST calls."""

from __future__ import annotations

import asyncio
from typing import TYPE_CHECKING, Any

import httpx

if TYPE_CHECKING:
    from app.settings import Settings


class OpenEmrHttpPool:
    """Limits connections, timeouts, and concurrent in-flight calls to OpenEMR."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._semaphore = asyncio.Semaphore(settings.openemr_max_concurrent_requests)
        timeout = httpx.Timeout(
            connect=settings.openemr_http_timeout_connect_s,
            read=settings.openemr_http_timeout_read_s,
            write=settings.openemr_http_timeout_read_s,
            pool=settings.openemr_http_timeout_connect_s,
        )
        limits = httpx.Limits(
            max_connections=settings.openemr_http_max_connections,
            max_keepalive_connections=settings.openemr_http_max_keepalive,
        )
        self._client = httpx.AsyncClient(timeout=timeout, limits=limits, follow_redirects=True)

    @property
    def base_url(self) -> str:
        return self._settings.openemr_base_url()

    async def aclose(self) -> None:
        await self._client.aclose()

    async def probe_livez(self) -> tuple[bool, str | None]:
        """Return (ok, error_detail) after hitting OpenEMR liveness (lightweight).

        Does not acquire the tool-call semaphore so readiness is not blocked
        when OpenEMR traffic is saturated.
        """
        url = f"{self.base_url}/meta/health/livez"
        try:
            response = await self._client.get(url)
        except httpx.HTTPError as exc:
            return False, str(exc)
        if response.status_code >= 400:
            return False, f"HTTP {response.status_code}"
        try:
            data = response.json()
        except ValueError:
            return False, "invalid JSON"
        if data.get("status") == "alive":
            return True, None
        return False, "unexpected body"

    async def request(self, method: str, url: str, **kwargs: Any) -> httpx.Response:
        """Perform a bounded OpenEMR HTTP call (use for future REST tools)."""
        async with self._semaphore:
            return await self._client.request(method, url, **kwargs)
