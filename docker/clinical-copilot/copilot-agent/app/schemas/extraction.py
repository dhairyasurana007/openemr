"""Pydantic-validated output contracts for VLM document extraction."""

from __future__ import annotations

from typing import Annotated, Literal

from pydantic import BaseModel, Field


class ExtractionCitation(BaseModel):
    """Points back to the exact location in the source document for a claimed value."""

    source_type: str = Field(
        min_length=1,
        description="Origin kind: 'lab_pdf', 'intake_form', 'guideline_chunk', etc.",
    )
    source_id: str = Field(
        min_length=1,
        description="Stable document identifier (file hash, upload UUID, guideline slug).",
    )
    page_or_section: str = Field(
        min_length=1,
        description="Human-readable locator: page number, section heading, or chunk id.",
    )
    field_or_chunk_id: str = Field(
        min_length=1,
        description="Fine-grained field name or chunk identifier within the page/section.",
    )
    quote_or_value: str = Field(
        min_length=1,
        description="Verbatim text excerpt or raw value from the source that supports the claim.",
    )
    bbox: tuple[float, float, float, float] | None = Field(
        default=None,
        description="Bounding box (x0, y0, x1, y1) in PDF points, origin top-left. Null for HL7/DOCX/XLSX.",
    )
    page_number: int | None = Field(
        default=None,
        description="1-indexed page number within the source document. Null for non-paginated formats.",
    )


class LabResult(BaseModel):
    """A single analyte extracted from a lab report."""

    test_name: str = Field(min_length=1)
    value: str = Field(min_length=1, description="Raw result value as printed (e.g. '5.2', '>100').")
    unit: str = Field(default="", description="Unit string as printed (e.g. 'mg/dL'). Empty when absent.")
    reference_range: str = Field(default="", description="Reference interval string (e.g. '3.5–5.0'). Empty when absent.")
    collection_date: str = Field(default="", description="ISO-8601 date or free-text date as printed. Empty when not found.")
    abnormal_flag: str = Field(
        default="",
        description="Abnormality indicator as printed: 'H', 'L', 'HH', 'LL', 'A', or empty when normal/absent.",
    )
    confidence: Annotated[float, Field(ge=0.0, le=1.0)] = Field(
        description="VLM extraction confidence for this result (0.0 = no confidence, 1.0 = certain).",
    )
    citation: ExtractionCitation


class LabExtractionResult(BaseModel):
    """Top-level result for a lab PDF extraction."""

    schema_version: Literal["1.0.0"] = "1.0.0"
    doc_type: Literal["lab_pdf"] = "lab_pdf"
    results: list[LabResult] = Field(default_factory=list)
    extraction_warnings: list[str] = Field(
        default_factory=list,
        description="Non-fatal issues encountered during extraction (e.g. low-quality scan, partial read).",
    )


class PatientDemographics(BaseModel):
    """Basic demographic fields parsed from an intake form."""

    name: str = Field(default="")
    dob: str = Field(default="", description="Date of birth as printed (ISO-8601 preferred).")
    sex: str = Field(default="")
    address: str = Field(default="")


class IntakeFormResult(BaseModel):
    """Top-level result for an intake form extraction."""

    schema_version: Literal["1.0.0"] = "1.0.0"
    doc_type: Literal["intake_form"] = "intake_form"
    demographics: PatientDemographics = Field(default_factory=PatientDemographics)
    chief_concern: str = Field(default="", description="Patient's stated chief complaint or reason for visit.")
    current_medications: list[str] = Field(default_factory=list)
    allergies: list[str] = Field(default_factory=list)
    family_history: list[str] = Field(default_factory=list)
    extraction_warnings: list[str] = Field(default_factory=list)
    citation: ExtractionCitation
