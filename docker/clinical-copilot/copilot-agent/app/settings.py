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


def _standard_api_path_prefix() -> str:
    """OpenEMR serves the standard API at ``/apis/{site_id}/api/...`` (default site: ``default``)."""
    raw = (os.environ.get("OPENEMR_STANDARD_API_PATH_PREFIX") or "/apis/default/api").strip()
    if raw == "":
        return "/apis/default/api"
    return "/" + raw.strip("/")


@dataclass(frozen=True)
class Settings:
    """Runtime configuration loaded once at process start."""

    openrouter_api_key: str
    """OpenRouter API key (Chat Completions-compatible HTTP API; model id e.g. anthropic/claude-*). Empty disables chat."""
    openrouter_model: str
    """OpenRouter model id, e.g. ``anthropic/claude-3.5-haiku``."""
    openrouter_model_uc4: str
    """Optional OpenRouter model override for UC4 (in-room Q&A). Empty means use ``openrouter_model``."""
    openrouter_http_timeout_s: float
    """Total-ish timeout for a single completion (passed to LangChain client)."""
    openrouter_http_referer: str
    """OpenRouter optional ``HTTP-Referer`` header."""
    openrouter_app_title: str
    """OpenRouter optional ``X-Title`` header."""
    clinical_copilot_internal_secret: str
    """When non-empty, ``POST /v1/chat`` requires matching ``X-Clinical-Copilot-Internal-Secret``."""
    openemr_internal_hostport: str
    """Host:port or full URL for OpenEMR HTTP (e.g. ``openemr-web:80``). Document root; not the API prefix."""
    openemr_standard_api_path_prefix: str
    """Path prefix for OpenEMR standard REST API (e.g. ``/apis/default/api``). Retrieval URLs are under this + ``/clinical-copilot/retrieval/``."""
    openemr_http_verify: bool
    """Verify TLS certificates for agent→OpenEMR HTTPS. Set false only for private self-signed (e.g. ``openemr-web:443`` in Compose)."""
    openemr_http_timeout_connect_s: float
    openemr_http_timeout_read_s: float
    openemr_http_max_connections: int
    openemr_http_max_keepalive: int
    openemr_max_concurrent_requests: int
    """Semaphore limit for concurrent agent→OpenEMR HTTP calls (backpressure)."""
    readyz_probe_openemr: bool
    """When True, /meta/health/readyz awaits OpenEMR /meta/health/livez (stricter deploy ordering)."""
    use_openemr_retrieval: bool
    """When True, retrieval tools call OpenEMR standard API under ``openemr_standard_api_path_prefix``. When False, empty stub."""
    copilot_max_inflight: int
    """When >0, cap concurrent non-health requests (503 when saturated)."""
    vlm_model: str
    """OpenRouter model id used for VLM document extraction (e.g. ``anthropic/claude-sonnet-4.6``)."""
    cohere_api_key: str
    """Cohere API key for optional reranking in the guideline RAG pipeline. Empty disables reranking."""
    embedding_model: str
    """Sentence-transformers model name for dense guideline retrieval (e.g. ``all-MiniLM-L6-v2``)."""
    guidelines_corpus_dir: str
    """Directory containing fetched guideline plain-text files (e.g. ``app/guidelines``)."""
    langchain_api_key: str
    """LangSmith API key (``LANGCHAIN_API_KEY``). Empty disables authenticated tracing."""
    langchain_tracing_v2: bool
    """``LANGCHAIN_TRACING_V2``: send LangChain runs to LangSmith when true and key is set."""
    langchain_project: str
    """LangSmith project name (``LANGCHAIN_PROJECT``)."""
    langchain_endpoint: str
    """LangSmith API base URL (``LANGCHAIN_ENDPOINT``); empty = client default."""

    @staticmethod
    def load() -> Settings:
        api_key = (
            (os.environ.get("LANGCHAIN_API_KEY") or os.environ.get("LANGSMITH_API_KEY") or "").strip()
        )
        tracing_raw = os.environ.get("LANGCHAIN_TRACING_V2")
        if tracing_raw is None or str(tracing_raw).strip() == "":
            langchain_tracing_v2 = api_key != ""
        else:
            langchain_tracing_v2 = str(tracing_raw).strip().lower() in ("1", "true", "yes", "on")
        if api_key == "":
            langchain_tracing_v2 = False

        return Settings(
            openrouter_api_key=(os.environ.get("OPENROUTER_API_KEY") or "").strip(),
            openrouter_model=(os.environ.get("OPENROUTER_MODEL") or "anthropic/claude-3.5-haiku").strip(),
            openrouter_model_uc4=(os.environ.get("OPENROUTER_MODEL_UC4") or "").strip(),
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
            openemr_standard_api_path_prefix=_standard_api_path_prefix(),
            openemr_http_verify=_bool("OPENEMR_HTTP_VERIFY", True),
            openemr_http_timeout_connect_s=_float("OPENEMR_HTTP_TIMEOUT_CONNECT_S", 2.0),
            openemr_http_timeout_read_s=_float("OPENEMR_HTTP_TIMEOUT_READ_S", 30.0),
            openemr_http_max_connections=_int("OPENEMR_HTTP_MAX_CONNECTIONS", 20),
            openemr_http_max_keepalive=_int("OPENEMR_HTTP_MAX_KEEPALIVE_CONNECTIONS", 10),
            openemr_max_concurrent_requests=_int("OPENEMR_MAX_CONCURRENT_REQUESTS", 8),
            readyz_probe_openemr=_bool("COPILOT_READYZ_PROBE_OPENEMR", False),
            use_openemr_retrieval=_bool("COPILOT_USE_OPENEMR_RETRIEVAL", True),
            copilot_max_inflight=_int("COPILOT_MAX_INFLIGHT", 0),
            vlm_model=(os.environ.get("VLM_MODEL") or "anthropic/claude-sonnet-4.6").strip(),
            cohere_api_key=(os.environ.get("COHERE_API_KEY") or "").strip(),
            embedding_model=(os.environ.get("EMBEDDING_MODEL") or "all-MiniLM-L6-v2").strip(),
            guidelines_corpus_dir=(os.environ.get("GUIDELINES_CORPUS_DIR") or "app/guidelines").strip(),
            langchain_api_key=api_key,
            langchain_tracing_v2=langchain_tracing_v2,
            langchain_project=(os.environ.get("LANGCHAIN_PROJECT") or "clinical-copilot").strip(),
            langchain_endpoint=(os.environ.get("LANGCHAIN_ENDPOINT") or "").strip(),
        )

    def openemr_base_url(self) -> str:
        hostport = self.openemr_internal_hostport
        if "://" in hostport:
            return hostport.rstrip("/")
        return f"http://{hostport}".rstrip("/")
