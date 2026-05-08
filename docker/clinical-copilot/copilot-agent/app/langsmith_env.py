"""Sync LangSmith / LangChain client env from Settings (matches docker-compose LANGCHAIN_* vars)."""

from __future__ import annotations

import os

from app.settings import Settings


def apply_langchain_runtime_env(settings: Settings) -> None:
    """Set LANGCHAIN_* so LangChain/LangSmith pick them up before traced runs (e.g. chat invoke)."""
    key = settings.langchain_api_key.strip()
    if key != "":
        os.environ["LANGCHAIN_API_KEY"] = key
    else:
        os.environ.pop("LANGCHAIN_API_KEY", None)

    os.environ["LANGCHAIN_TRACING_V2"] = "true" if settings.langchain_tracing_v2 else "false"

    proj = settings.langchain_project.strip()
    if proj != "":
        os.environ["LANGCHAIN_PROJECT"] = proj
    else:
        os.environ.pop("LANGCHAIN_PROJECT", None)

    endpoint = settings.langchain_endpoint.strip()
    if endpoint != "":
        os.environ["LANGCHAIN_ENDPOINT"] = endpoint
    else:
        os.environ.pop("LANGCHAIN_ENDPOINT", None)

    # Keep LangSmith run payloads visible for debugging and evaluation workflows.
    os.environ["LANGCHAIN_HIDE_INPUTS"] = "false"
    os.environ["LANGCHAIN_HIDE_OUTPUTS"] = "false"
