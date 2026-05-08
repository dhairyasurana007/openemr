"""Tests for Pydantic extraction output schemas."""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.schemas.extraction import (
    ExtractionCitation,
    IntakeFormResult,
    LabExtractionResult,
    LabResult,
    PatientDemographics,
)


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

def _citation(**overrides) -> dict:
    base = {
        "source_type": "lab",
        "source_id": "sha256:abc123",
        "page_or_section": "page 1",
        "field_or_chunk_id": "sodium",
        "quote_or_value": "Na 138 mEq/L",
        "bbox": None,
        "page_number": None,
    }
    base.update(overrides)
    return base


def _lab_result(**overrides) -> dict:
    base = {
        "test_name": "Sodium",
        "value": "138",
        "unit": "mEq/L",
        "reference_range": "136–145",
        "collection_date": "2026-04-01",
        "abnormal_flag": "",
        "confidence": 0.97,
        "citation": _citation(),
    }
    base.update(overrides)
    return base


def _lab_extraction(**overrides) -> dict:
    base = {
        "results": [_lab_result()],
        "extraction_warnings": [],
    }
    base.update(overrides)
    return base


def _intake_citation(**overrides) -> dict:
    return _citation(
        source_type="intake_form",
        field_or_chunk_id="chief_concern",
        quote_or_value="chest pain for 2 days",
        **overrides,
    )


def _intake_form(**overrides) -> dict:
    base = {
        "demographics": {"name": "Jane Doe", "dob": "1980-03-15", "sex": "F", "address": "123 Main St"},
        "chief_concern": "chest pain for 2 days",
        "current_medications": ["lisinopril 10 mg", "atorvastatin 20 mg"],
        "allergies": ["penicillin"],
        "family_history": ["hypertension", "type 2 diabetes"],
        "extraction_warnings": [],
        "citation": _intake_citation(),
    }
    base.update(overrides)
    return base


# ---------------------------------------------------------------------------
# ExtractionCitation
# ---------------------------------------------------------------------------

class TestExtractionCitation:
    def test_valid_round_trip(self) -> None:
        data = _citation()
        obj = ExtractionCitation.model_validate(data)
        assert obj.source_type == "lab"
        assert obj.source_id == "sha256:abc123"
        assert obj.page_or_section == "page 1"
        assert obj.field_or_chunk_id == "sodium"
        assert obj.quote_or_value == "Na 138 mEq/L"
        assert obj.model_dump() == data

    def test_all_fields_required(self) -> None:
        required = ["source_type", "source_id", "page_or_section", "field_or_chunk_id", "quote_or_value"]
        for field in required:
            bad = _citation()
            del bad[field]
            with pytest.raises(ValidationError):
                ExtractionCitation.model_validate(bad)

    def test_empty_string_rejected(self) -> None:
        for field in ["source_type", "source_id", "page_or_section", "field_or_chunk_id", "quote_or_value"]:
            bad = _citation(**{field: ""})
            with pytest.raises(ValidationError):
                ExtractionCitation.model_validate(bad)

    def test_bbox_and_page_number_default_to_none(self) -> None:
        obj = ExtractionCitation.model_validate(_citation())
        assert obj.bbox is None
        assert obj.page_number is None

    def test_citation_with_bbox_and_page_number(self) -> None:
        data = _citation(bbox=[10.0, 20.0, 100.0, 50.0], page_number=2)
        obj = ExtractionCitation.model_validate(data)
        assert obj.bbox == (10.0, 20.0, 100.0, 50.0)
        assert obj.page_number == 2

    def test_bbox_none_is_accepted(self) -> None:
        data = _citation(bbox=None, page_number=None)
        obj = ExtractionCitation.model_validate(data)
        assert obj.bbox is None
        assert obj.page_number is None


# ---------------------------------------------------------------------------
# LabResult
# ---------------------------------------------------------------------------

class TestLabResult:
    def test_valid_round_trip(self) -> None:
        obj = LabResult.model_validate(_lab_result())
        assert obj.test_name == "Sodium"
        assert obj.value == "138"
        assert obj.confidence == 0.97
        assert isinstance(obj.citation, ExtractionCitation)

    def test_required_fields(self) -> None:
        for field in ["test_name", "value", "confidence", "citation"]:
            bad = _lab_result()
            del bad[field]
            with pytest.raises(ValidationError):
                LabResult.model_validate(bad)

    def test_optional_fields_have_defaults(self) -> None:
        minimal = {"test_name": "Glucose", "value": "95", "confidence": 0.9, "citation": _citation()}
        obj = LabResult.model_validate(minimal)
        assert obj.unit == ""
        assert obj.reference_range == ""
        assert obj.collection_date == ""
        assert obj.abnormal_flag == ""

    def test_confidence_lower_bound(self) -> None:
        obj = LabResult.model_validate(_lab_result(confidence=0.0))
        assert obj.confidence == 0.0

    def test_confidence_upper_bound(self) -> None:
        obj = LabResult.model_validate(_lab_result(confidence=1.0))
        assert obj.confidence == 1.0

    def test_confidence_below_zero_rejected(self) -> None:
        with pytest.raises(ValidationError):
            LabResult.model_validate(_lab_result(confidence=-0.01))

    def test_confidence_above_one_rejected(self) -> None:
        with pytest.raises(ValidationError):
            LabResult.model_validate(_lab_result(confidence=1.001))

    def test_citation_shape_enforced(self) -> None:
        bad = _lab_result()
        bad["citation"] = {"source_type": "lab"}  # missing required fields
        with pytest.raises(ValidationError):
            LabResult.model_validate(bad)


# ---------------------------------------------------------------------------
# LabExtractionResult
# ---------------------------------------------------------------------------

class TestLabExtractionResult:
    def test_valid_round_trip(self) -> None:
        obj = LabExtractionResult.model_validate(_lab_extraction())
        assert obj.schema_version == "1.0.0"
        assert obj.doc_type == "lab"
        assert len(obj.results) == 1
        assert obj.extraction_warnings == []

    def test_schema_version_fixed(self) -> None:
        bad = _lab_extraction()
        bad["schema_version"] = "2.0.0"
        with pytest.raises(ValidationError):
            LabExtractionResult.model_validate(bad)

    def test_doc_type_fixed(self) -> None:
        bad = _lab_extraction()
        bad["doc_type"] = "intake_form"
        with pytest.raises(ValidationError):
            LabExtractionResult.model_validate(bad)

    def test_empty_results_allowed(self) -> None:
        obj = LabExtractionResult.model_validate(_lab_extraction(results=[]))
        assert obj.results == []

    def test_multiple_results(self) -> None:
        results = [_lab_result(), _lab_result(test_name="Potassium", value="4.1")]
        obj = LabExtractionResult.model_validate(_lab_extraction(results=results))
        assert len(obj.results) == 2

    def test_warnings_list(self) -> None:
        obj = LabExtractionResult.model_validate(_lab_extraction(extraction_warnings=["low scan quality"]))
        assert obj.extraction_warnings == ["low scan quality"]

    def test_invalid_result_rejected(self) -> None:
        bad = _lab_extraction(results=[{"test_name": "X"}])  # missing required fields
        with pytest.raises(ValidationError):
            LabExtractionResult.model_validate(bad)


# ---------------------------------------------------------------------------
# IntakeFormResult
# ---------------------------------------------------------------------------

class TestIntakeFormResult:
    def test_valid_round_trip(self) -> None:
        obj = IntakeFormResult.model_validate(_intake_form())
        assert obj.schema_version == "1.0.0"
        assert obj.doc_type == "intake_form"
        assert obj.chief_concern == "chest pain for 2 days"
        assert obj.current_medications == ["lisinopril 10 mg", "atorvastatin 20 mg"]
        assert obj.allergies == ["penicillin"]
        assert obj.family_history == ["hypertension", "type 2 diabetes"]
        assert isinstance(obj.citation, ExtractionCitation)

    def test_citation_required(self) -> None:
        bad = _intake_form()
        del bad["citation"]
        with pytest.raises(ValidationError):
            IntakeFormResult.model_validate(bad)

    def test_citation_shape_enforced(self) -> None:
        bad = _intake_form()
        bad["citation"] = {"source_type": "intake_form"}  # missing required fields
        with pytest.raises(ValidationError):
            IntakeFormResult.model_validate(bad)

    def test_schema_version_fixed(self) -> None:
        bad = _intake_form()
        bad["schema_version"] = "9.9.9"
        with pytest.raises(ValidationError):
            IntakeFormResult.model_validate(bad)

    def test_doc_type_fixed(self) -> None:
        bad = _intake_form()
        bad["doc_type"] = "lab"
        with pytest.raises(ValidationError):
            IntakeFormResult.model_validate(bad)

    def test_optional_fields_default_to_empty(self) -> None:
        minimal = {"citation": _intake_citation()}
        obj = IntakeFormResult.model_validate(minimal)
        assert obj.chief_concern == ""
        assert obj.current_medications == []
        assert obj.allergies == []
        assert obj.family_history == []
        assert obj.extraction_warnings == []
        assert isinstance(obj.demographics, PatientDemographics)

    def test_demographics_fields(self) -> None:
        obj = IntakeFormResult.model_validate(_intake_form())
        assert obj.demographics.name == "Jane Doe"
        assert obj.demographics.dob == "1980-03-15"
        assert obj.demographics.sex == "F"
        assert obj.demographics.address == "123 Main St"

    def test_demographics_defaults_to_empty_strings(self) -> None:
        form = _intake_form(demographics={})
        obj = IntakeFormResult.model_validate(form)
        assert obj.demographics.name == ""
        assert obj.demographics.dob == ""
        assert obj.demographics.sex == ""
        assert obj.demographics.address == ""
