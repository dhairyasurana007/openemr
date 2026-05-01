"""FastAPI entrypoint: JSON-backed retrieval for the Clinical Co-Pilot."""

from fastapi import Depends, FastAPI
from fastapi.responses import JSONResponse

from app.auth import require_admin
from app.config import COPILOT_ROOT, DATA_DIR
from app.store import data_dir_exists, load_table, patient_bundle

app = FastAPI(
    title="Clinical Co-Pilot (JSON store)",
    description="Read-only retrieval using OpenEMR-shaped JSON files; no database.",
    version="0.1.0",
)


@app.get("/health")
def health() -> dict[str, str | bool]:
    return {
        "status": "ok",
        "store": "json",
        "data_dir_ready": data_dir_exists(),
    }


@app.get("/api/v1/meta")
def meta(_user: str = Depends(require_admin)) -> dict[str, object]:
    return {
        "copilot_root": str(COPILOT_ROOT.resolve()),
        "data_dir": str(DATA_DIR.resolve()),
        "tables": [
            "patient_data",
            "form_encounter",
            "lists",
            "prescriptions",
            "procedure_order",
            "procedure_report",
            "procedure_result",
            "form_vitals",
            "users",
        ],
    }


@app.get("/api/v1/table/{table_name}")
def get_table(table_name: str, _user: str = Depends(require_admin)) -> JSONResponse:
    allowed = {
        "patient_data",
        "form_encounter",
        "lists",
        "prescriptions",
        "procedure_order",
        "procedure_report",
        "procedure_result",
        "form_vitals",
        "users",
    }
    if table_name not in allowed:
        return JSONResponse(status_code=404, content={"detail": "Unknown table"})
    return JSONResponse(content=load_table(table_name))


@app.get("/api/v1/patient/{pid}/bundle")
def get_patient_bundle(pid: int, _user: str = Depends(require_admin)) -> dict[str, object]:
    return patient_bundle(pid)
