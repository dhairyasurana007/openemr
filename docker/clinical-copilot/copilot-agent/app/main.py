"""Minimal FastAPI shell for the Clinical Co-Pilot agent (LangChain + OpenRouter wiring comes next)."""

from fastapi import FastAPI

app = FastAPI(title="Clinical Co-Pilot Agent", version="0.1.0")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}
