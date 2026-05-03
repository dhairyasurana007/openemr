"""HTTP retrieval backend: proxies copilot retrieval routes on OpenEMR (read-only JSON)."""

from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any

import httpx

from app.retrieval_backends import RetrievalBackend
from app.settings import Settings


def _agent_debug_ndjson(
    *,
    hypothesis_id: str,
    location: str,
    message: str,
    data: dict[str, Any],
) -> None:
    # region agent log
    payload: dict[str, Any] = {
        "sessionId": "16a0eb",
        "hypothesisId": hypothesis_id,
        "location": location,
        "message": message,
        "data": data,
        "timestamp": int(time.time() * 1000),
    }
    line = json.dumps(payload, default=str) + "\n"
    here = Path(__file__).resolve()
    candidates: list[Path] = []
    for depth in (4, 3, 2):
        if len(here.parents) <= depth:
            continue
        ancestor = here.parents[depth]
        if (ancestor / "composer.json").is_file():
            candidates.append(ancestor / "debug-16a0eb.log")
            break
    candidates.append(Path.cwd() / "debug-16a0eb.log")
    candidates.append(Path("/tmp/debug-16a0eb.log"))
    for dest in candidates:
        try:
            dest.parent.mkdir(parents=True, exist_ok=True)
            with dest.open("a", encoding="utf-8") as fp:
                fp.write(line)
            break
        except OSError:
            continue
    # endregion


class OpenEmrRetrievalBackend:
    """Sync httpx client to OpenEMR standard API ``…/clinical-copilot/retrieval/*`` (same contract as ``StubRetrievalBackend``)."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        api_base = settings.openemr_standard_api_path_prefix.rstrip("/")
        self._prefix = f"{api_base}/clinical-copilot/retrieval"
        self._client = httpx.Client(
            base_url=settings.openemr_base_url().rstrip("/"),
            verify=settings.openemr_http_verify,
            timeout=httpx.Timeout(
                connect=settings.openemr_http_timeout_connect_s,
                read=settings.openemr_http_timeout_read_s,
                write=settings.openemr_http_timeout_read_s,
                pool=settings.openemr_http_timeout_connect_s,
            ),
            limits=httpx.Limits(
                max_connections=settings.openemr_http_max_connections,
                max_keepalive_connections=settings.openemr_http_max_keepalive,
            ),
            follow_redirects=True,
        )
        # region agent log
        _agent_debug_ndjson(
            hypothesis_id="H1-H4",
            location="openemr_retrieval_backend.py:OpenEmrRetrievalBackend.__init__",
            message="retrieval_http_client_config",
            data={
                "openemr_base_url": str(self._client.base_url),
                "api_prefix": self._prefix,
                "openemr_internal_hostport": settings.openemr_internal_hostport,
                "openemr_http_verify": settings.openemr_http_verify,
                "use_openemr_retrieval": settings.use_openemr_retrieval,
            },
        )
        # endregion

    def close(self) -> None:
        self._client.close()

    def _headers(self) -> dict[str, str]:
        secret = self._settings.clinical_copilot_internal_secret
        if secret == "":
            return {}
        return {"X-Clinical-Copilot-Internal-Secret": secret}

    def _get_json(self, path: str, params: dict[str, str], tool: str) -> dict[str, Any]:
        q = {k: v for k, v in params.items() if v != ""}
        try:
            response = self._client.get(path, params=q, headers=self._headers())
        except httpx.HTTPError as exc:
            # region agent log
            _agent_debug_ndjson(
                hypothesis_id="H1-H5",
                location="openemr_retrieval_backend.py:_get_json",
                message="retrieval_http_transport_error",
                data={
                    "tool": tool,
                    "path": path,
                    "param_keys": sorted(q.keys()),
                    "base_url": str(self._client.base_url),
                    "exc_type": type(exc).__name__,
                    "exc_str": str(exc)[:500],
                },
            )
            # endregion
            return {
                "tool": tool,
                "schema_version": "1",
                "citations": [],
                "retrieval_status": {"ok": False, "code": "http_transport", "detail": str(exc)},
            }
        try:
            data = response.json()
        except (TypeError, ValueError, json.JSONDecodeError):
            return {
                "tool": tool,
                "schema_version": "1",
                "citations": [],
                "retrieval_status": {
                    "ok": False,
                    "code": "invalid_json",
                    "detail": f"HTTP {response.status_code}",
                },
            }
        if isinstance(data, dict):
            data.setdefault("tool", tool)
            data.setdefault("schema_version", "1")
            if response.status_code >= 400:
                data.setdefault(
                    "retrieval_status",
                    {"ok": False, "code": "http_error", "detail": f"HTTP {response.status_code}"},
                )
            return data
        return {
            "tool": tool,
            "schema_version": "1",
            "citations": [],
            "retrieval_status": {"ok": False, "code": "unexpected_body", "detail": "response was not a JSON object"},
        }

    def list_schedule_slots(self, date: str, facility_id: str = "") -> dict[str, Any]:
        return self._get_json(
            f"{self._prefix}/list-schedule-slots",
            {"date": date, "facility_id": facility_id},
            "list_schedule_slots",
        )

    def get_calendar(
        self,
        start_date: str,
        end_date: str = "",
        calendar_id: str = "",
        facility_id: str = "",
    ) -> dict[str, Any]:
        return self._get_json(
            f"{self._prefix}/calendar",
            {
                "start_date": start_date,
                "end_date": end_date,
                "calendar_id": calendar_id,
                "facility_id": facility_id,
            },
            "get_calendar",
        )

    def get_patient_core_profile(self, patient_uuid: str) -> dict[str, Any]:
        return self._get_json(f"{self._prefix}/patient-core-profile", {"patient": patient_uuid}, "get_patient_core_profile")

    def get_medication_list(self, patient_uuid: str) -> dict[str, Any]:
        return self._get_json(f"{self._prefix}/medication-list", {"patient": patient_uuid}, "get_medication_list")

    def get_observations(self, patient_uuid: str) -> dict[str, Any]:
        return self._get_json(f"{self._prefix}/observations", {"patient": patient_uuid}, "get_observations")

    def get_encounters_and_notes(self, patient_uuid: str) -> dict[str, Any]:
        return self._get_json(
            f"{self._prefix}/encounters-and-notes",
            {"patient": patient_uuid},
            "get_encounters_and_notes",
        )

    def get_referrals_orders_care_gaps(self, patient_uuid: str) -> dict[str, Any]:
        return self._get_json(
            f"{self._prefix}/referrals-orders-care-gaps",
            {"patient": patient_uuid},
            "get_referrals_orders_care_gaps",
        )


def retrieval_backend_for_runtime(settings: Settings) -> RetrievalBackend:
    """OpenEMR HTTP when enabled; otherwise in-process empty shells (CI / no stack)."""
    if settings.use_openemr_retrieval:
        return OpenEmrRetrievalBackend(settings)
    from app.retrieval_backends import StubRetrievalBackend

    return StubRetrievalBackend()
