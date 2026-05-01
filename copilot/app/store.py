"""Load clinical tables from JSON files (OpenEMR column names, no SQL)."""

import json
from typing import Any

from app.config import DATA_DIR

_TABLE_FILES: dict[str, str] = {
    "patient_data": "patient_data.json",
    "form_encounter": "form_encounter.json",
    "lists": "lists.json",
    "prescriptions": "prescriptions.json",
    "procedure_order": "procedure_order.json",
    "procedure_report": "procedure_report.json",
    "procedure_result": "procedure_result.json",
    "form_vitals": "form_vitals.json",
    "users": "users.json",
}


def _read_table_file(name: str) -> list[dict[str, Any]]:
    filename = _TABLE_FILES.get(name)
    if filename is None:
        return []
    path = DATA_DIR / filename
    if not path.is_file():
        return []
    raw = path.read_text(encoding="utf-8")
    data = json.loads(raw)
    if not isinstance(data, list):
        return []
    return [row for row in data if isinstance(row, dict)]


def load_table(table: str) -> list[dict[str, Any]]:
    return _read_table_file(table)


def patient_bundle(pid: int) -> dict[str, Any]:
    """Return rows for one patient across copilot retrieval tables."""
    pid_str = str(pid)
    encounters = [r for r in load_table("form_encounter") if int(r.get("pid") or 0) == pid]
    encounter_ids = {int(r.get("encounter") or 0) for r in encounters}

    patients = [r for r in load_table("patient_data") if int(r.get("pid") or 0) == pid]
    patient_row = patients[0] if patients else None

    lists_rows = [r for r in load_table("lists") if int(r.get("pid") or 0) == pid]
    rx_rows = [r for r in load_table("prescriptions") if int(r.get("patient_id") or 0) == pid]
    vitals_rows = [r for r in load_table("form_vitals") if int(r.get("pid") or 0) == pid]

    orders = [r for r in load_table("procedure_order") if int(r.get("patient_id") or 0) == pid]
    order_ids = {int(r.get("procedure_order_id") or 0) for r in orders}
    reports = [
        r
        for r in load_table("procedure_report")
        if int(r.get("procedure_order_id") or 0) in order_ids
    ]
    report_ids = {int(r.get("procedure_report_id") or 0) for r in reports}
    results = [
        r
        for r in load_table("procedure_result")
        if int(r.get("procedure_report_id") or 0) in report_ids
    ]

    users_rows: list[dict[str, Any]] = []
    user_ids: set[int] = set()
    for r in encounters:
        uid = int(r.get("provider_id") or 0)
        if uid:
            user_ids.add(uid)
    for r in rx_rows:
        uid = int(r.get("provider_id") or 0)
        if uid:
            user_ids.add(uid)
    if patient_row:
        uid = int(patient_row.get("providerID") or 0)
        if uid:
            user_ids.add(uid)
    for u in load_table("users"):
        if int(u.get("id") or 0) in user_ids:
            users_rows.append(u)

    return {
        "pid": pid,
        "patient_data": patient_row,
        "form_encounter": encounters,
        "lists": lists_rows,
        "prescriptions": rx_rows,
        "procedure_order": orders,
        "procedure_report": reports,
        "procedure_result": results,
        "form_vitals": vitals_rows,
        "users": users_rows,
        "meta": {
            "encounter_ids": sorted(encounter_ids),
            "data_dir": str(DATA_DIR.resolve()),
        },
    }


def data_dir_exists() -> bool:
    return DATA_DIR.is_dir()
