"""Environment-driven settings for outbound OpenEMR HTTP and readiness probes."""

from __future__ import annotations

import os
from dataclasses import dataclass


def _float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    return float(raw)


def _int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    return int(raw)


def _bool(name: str, default: bool) -> bool:
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


@dataclass(frozen=True)
class Settings:
    """Runtime configuration loaded once at process start."""

    openrouter_api_key: str
    """API key for OpenRouter (OpenAI-compatible base URL). Empty disables chat."""
    openrouter_model: str
    """OpenRouter model id, e.g. ``openai/gpt-4o-mini``."""
    openrouter_http_timeout_s: float
    """Total-ish timeout for a single completion (passed to LangChain client)."""
    openrouter_http_referer: str
    """OpenRouter optional ``HTTP-Referer`` header."""
    openrouter_app_title: str
    """OpenRouter optional ``X-Title`` header."""
    clinical_copilot_internal_secret: str
    """When non-empty, ``POST /v1/chat`` requires matching ``X-Clinical-Copilot-Internal-Secret``."""
    openemr_internal_hostport: str
    openemr_http_timeout_connect_s: float
    openemr_http_timeout_read_s: float
    openemr_http_max_connections: int
    openemr_http_max_keepalive: int
    openemr_max_concurrent_requests: int
    """Semaphore limit for concurrent agent→OpenEMR HTTP calls (backpressure)."""
    readyz_probe_openemr: bool
    """When True, /meta/health/readyz awaits OpenEMR /meta/health/livez (stricter deploy ordering)."""
    copilot_max_inflight: int
    """When >0, cap concurrent non-health requests (503 when saturated)."""

    @staticmethod
    def load() -> Settings:
        return Settings(
            openrouter_api_key=(os.environ.get("OPENROUTER_API_KEY") or "").strip(),
            openrouter_model=(os.environ.get("OPENROUTER_MODEL") or "openai/gpt-4o-mini").strip(),
            openrouter_http_timeout_s=_float("OPENROUTER_HTTP_TIMEOUT_S", 90.0),
            openrouter_http_referer=(
                os.environ.get("OPENROUTER_HTTP_REFERER") or "https://www.open-emr.org/"
            ).strip(),
            openrouter_app_title=(os.environ.get("OPENROUTER_APP_TITLE") or "OpenEMR Clinical Co-Pilot").strip(),
            clinical_copilot_internal_secret=(
                os.environ.get("CLINICAL_COPILOT_INTERNAL_SECRET") or ""
            ).strip(),
            openemr_internal_hostport=os.environ.get(
                "OPENEMR_INTERNAL_HOSTPORT", "openemr-web:80"
            ).strip(),
            openemr_http_timeout_connect_s=_float("OPENEMR_HTTP_TIMEOUT_CONNECT_S", 2.0),
            openemr_http_timeout_read_s=_float("OPENEMR_HTTP_TIMEOUT_READ_S", 30.0),
            openemr_http_max_connections=_int("OPENEMR_HTTP_MAX_CONNECTIONS", 20),
            openemr_http_max_keepalive=_int("OPENEMR_HTTP_MAX_KEEPALIVE_CONNECTIONS", 10),
            openemr_max_concurrent_requests=_int("OPENEMR_MAX_CONCURRENT_REQUESTS", 8),
            readyz_probe_openemr=_bool("COPILOT_READYZ_PROBE_OPENEMR", False),
            copilot_max_inflight=_int("COPILOT_MAX_INFLIGHT", 0),
        )

    def openemr_base_url(self) -> str:
        hostport = self.openemr_internal_hostport
        if "://" in hostport:
            return hostport.rstrip("/")
        return f"http://{hostport}".rstrip("/")
