"""FastAPI shell for the Clinical Co-Pilot agent (LangChain + OpenRouter wiring comes next)."""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import Any, AsyncIterator

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.chat import router as chat_router
from app.langsmith_env import apply_langchain_runtime_env
from app.middleware_access_log import AccessLogMiddleware
from app.middleware_inflight import InflightLimitMiddleware
from app.openemr_http import OpenEmrHttpPool
from app.openemr_retrieval_backend import OpenEmrRetrievalBackend, retrieval_backend_for_runtime
from app.settings import Settings

_LOG = logging.getLogger("clinical_copilot.main")

# Install PHI redaction on root logger before any other logging occurs so that
# library logs (httpx, langchain, etc.) are also scrubbed.
from app.log_redaction import PHIRedactionFilter as _PHIRedactionFilter  # noqa: E402
logging.getLogger().addFilter(_PHIRedactionFilter())

_SETTINGS = Settings.load()
apply_langchain_runtime_env(_SETTINGS)


@asynccontextmanager
async def _lifespan(app: FastAPI) -> AsyncIterator[None]:
    pool = OpenEmrHttpPool(_SETTINGS)
    app.state.settings = _SETTINGS
    app.state.openemr_pool = pool
    retrieval_backend = retrieval_backend_for_runtime(_SETTINGS)
    app.state.retrieval_backend = retrieval_backend

    try:
        from app.rag_retriever import HybridRetriever
        rag = HybridRetriever(
            corpus_dir=_SETTINGS.guidelines_corpus_dir,
            embedding_model_name=_SETTINGS.embedding_model,
            cohere_api_key=_SETTINGS.cohere_api_key,
        )
        app.state.rag_retriever = rag
        _LOG.info("rag_retriever_ready chunk_count=%d", rag.chunk_count)
    except ImportError:
        app.state.rag_retriever = None
        _LOG.warning("rag_retriever_unavailable reason=missing_dependencies")

    try:
        yield
    finally:
        if isinstance(retrieval_backend, OpenEmrRetrievalBackend):
            retrieval_backend.close()
        await pool.aclose()


app = FastAPI(
    title="Clinical Co-Pilot Agent",
    version="0.1.0",
    lifespan=_lifespan,
)

app.add_middleware(AccessLogMiddleware)

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
