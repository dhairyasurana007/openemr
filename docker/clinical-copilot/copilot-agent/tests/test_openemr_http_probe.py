"""Eval-style unit tests for agent→OpenEMR readiness probe (no running OpenEMR)."""

from __future__ import annotations

import unittest
from unittest.mock import AsyncMock, patch

import httpx

from app.openemr_http import OpenEmrHttpPool
from app.settings import Settings


def _minimal_settings() -> Settings:
    return Settings(
        openrouter_api_key="",
        openrouter_model="anthropic/claude-3.5-haiku",
        openrouter_http_timeout_s=30.0,
        openrouter_http_referer="https://www.open-emr.org/",
        openrouter_app_title="OpenEMR Clinical Co-Pilot",
        clinical_copilot_internal_secret="",
        openemr_internal_hostport="openemr-web:80",
        openemr_http_timeout_connect_s=1.0,
        openemr_http_timeout_read_s=2.0,
        openemr_http_max_connections=4,
        openemr_http_max_keepalive=2,
        openemr_max_concurrent_requests=2,
        readyz_probe_openemr=False,
        use_openemr_retrieval=False,
        copilot_max_inflight=0,
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


class TestOpenEmrHttpProbe(unittest.IsolatedAsyncioTestCase):
    async def asyncTearDown(self) -> None:
        await self._close_pool()

    async def _close_pool(self) -> None:
        pool = getattr(self, "_pool", None)
        if pool is not None:
            await pool.aclose()
            self._pool = None

    async def test_probe_livez_success(self) -> None:
        pool = OpenEmrHttpPool(_minimal_settings())
        self._pool = pool
        mock_response = httpx.Response(200, json={"status": "alive"})
        with patch.object(pool._client, "get", new=AsyncMock(return_value=mock_response)):
            ok, detail = await pool.probe_livez()
        self.assertTrue(ok)
        self.assertIsNone(detail)

    async def test_probe_livez_http_error(self) -> None:
        pool = OpenEmrHttpPool(_minimal_settings())
        self._pool = pool
        with patch.object(
            pool._client,
            "get",
            new=AsyncMock(side_effect=httpx.ConnectError("refused", request=httpx.Request("GET", "http://x"))),
        ):
            ok, detail = await pool.probe_livez()
        self.assertFalse(ok)
        self.assertIsNotNone(detail)

    async def test_probe_livez_bad_status(self) -> None:
        pool = OpenEmrHttpPool(_minimal_settings())
        self._pool = pool
        mock_response = httpx.Response(503, text="unavailable")
        with patch.object(pool._client, "get", new=AsyncMock(return_value=mock_response)):
            ok, detail = await pool.probe_livez()
        self.assertFalse(ok)
        self.assertEqual(detail, "HTTP 503")

    async def test_probe_livez_unexpected_json(self) -> None:
        pool = OpenEmrHttpPool(_minimal_settings())
        self._pool = pool
        mock_response = httpx.Response(200, json={"status": "wrong"})
        with patch.object(pool._client, "get", new=AsyncMock(return_value=mock_response)):
            ok, detail = await pool.probe_livez()
        self.assertFalse(ok)
        self.assertEqual(detail, "unexpected body")
