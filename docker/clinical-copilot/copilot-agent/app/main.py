"""FastAPI shell for the Clinical Co-Pilot agent (LangChain + OpenRouter wiring comes next)."""

from __future__ import annotations

from contextlib import asynccontextmanager
from typing import Any, AsyncIterator

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.chat import router as chat_router
from app.langsmith_env import apply_langchain_runtime_env
from app.middleware_inflight import InflightLimitMiddleware
from app.openemr_http import OpenEmrHttpPool
from app.settings import Settings

_SETTINGS = Settings.load()
apply_langchain_runtime_env(_SETTINGS)


@asynccontextmanager
async def _lifespan(app: FastAPI) -> AsyncIterator[None]:
    pool = OpenEmrHttpPool(_SETTINGS)
    app.state.settings = _SETTINGS
    app.state.openemr_pool = pool
    try:
        yield
    finally:
        await pool.aclose()


app = FastAPI(
    title="Clinical Co-Pilot Agent",
    version="0.1.0",
    lifespan=_lifespan,
)

if _SETTINGS.copilot_max_inflight > 0:
    app.add_middleware(InflightLimitMiddleware, max_inflight=_SETTINGS.copilot_max_inflight)

app.include_router(chat_router)


@app.get("/health")
def health() -> JSONResponse:
    """Liveness (Compose / legacy). Same class of check as ``/meta/health/livez``."""
    return JSONResponse({"status": "ok"})


@app.get("/meta/health/livez")
def livez() -> JSONResponse:
    """Process-only liveness: return 200 if the worker accepts HTTP (cheap probe)."""
    return JSONResponse({"status": "alive"})


@app.get("/meta/health/readyz")
async def readyz(request: Request) -> JSONResponse:
    """Readiness for orchestrators (e.g. Render ``healthCheckPath``).

    Default: process ready only (fast, no dependency fan-out). Set
    ``COPILOT_READYZ_PROBE_OPENEMR=true`` to require OpenEMR
    ``/meta/health/livez`` — use when the platform should not route to the
    agent until the app tier is reachable (avoid circular dependency with DB
    heavy ``readyz`` on OpenEMR itself).
    """
    settings: Settings = request.app.state.settings
    pool: OpenEmrHttpPool = request.app.state.openemr_pool

    checks: dict[str, Any] = {"process": True}

    if settings.readyz_probe_openemr:
        ok, detail = await pool.probe_livez()
        checks["openemr_livez"] = ok
        if not ok:
            body: dict[str, Any] = {
                "status": "not_ready",
                "checks": checks,
            }
            if detail:
                body["detail"] = detail
            return JSONResponse(body, status_code=503)

    return JSONResponse({"status": "ready", "checks": checks})
