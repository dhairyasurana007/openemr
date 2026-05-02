"""Minimal FastAPI shell for the Clinical Co-Pilot agent (LangChain + OpenRouter wiring comes next)."""

from fastapi import FastAPI
from fastapi.responses import JSONResponse

app = FastAPI(title="Clinical Co-Pilot Agent", version="0.1.0")

_READY = {"status": "ok"}


@app.get("/health")
def health() -> JSONResponse:
    """Liveness (Compose default)."""
    return JSONResponse(_READY)


@app.get("/meta/health/readyz")
def readyz() -> JSONResponse:
    """Readiness aligned with OpenEMR and Render healthCheckPath."""
    return JSONResponse(_READY)
