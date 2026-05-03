"""HTTP retrieval backend: proxies copilot retrieval routes on OpenEMR (read-only JSON)."""

from __future__ import annotations

import json
from typing import Any

import httpx

from app.retrieval_backends import RetrievalBackend
from app.settings import Settings


class OpenEmrRetrievalBackend:
    """Sync httpx client to OpenEMR standard API ``…/clinical-copilot/retrieval/*`` (same contract as ``StubRetrievalBackend``)."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        api_base = settings.openemr_standard_api_path_prefix.rstrip("/")
        self._prefix = f"{api_base}/clinical-copilot/retrieval"
        self._client = httpx.Client(
            base_url=settings.openemr_base_url().rstrip("/"),
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
