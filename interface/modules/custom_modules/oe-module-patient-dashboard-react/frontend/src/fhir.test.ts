import { describe, expect, it } from "vitest";
import { parseFhirDate, patientDisplayName, patientMrn, resourceLabel } from "./fhir";

describe("fhir helpers", () => {
  it("prefers resource text for labels", () => {
    expect(resourceLabel("Peanut allergy", [{ display: "SNOMED Label" }])).toBe("Peanut allergy");
  });

  it("falls back to coding display for labels", () => {
    expect(resourceLabel(undefined, [{ display: "Aspirin" }])).toBe("Aspirin");
  });

  it("builds patient display name from given and family names", () => {
    expect(
      patientDisplayName({
        name: [{ given: ["Taylor", "J"], family: "Smith" }],
      })
    ).toBe("Taylor J Smith");
  });

  it("resolves MRN from typed identifier", () => {
    expect(
      patientMrn({
        identifier: [
          { value: "ALT-10" },
          { type: { coding: [{ code: "MR" }] }, value: "MRN-12345" },
        ],
      })
    ).toBe("MRN-12345");
  });

  it("parses a valid FHIR timestamp", () => {
    expect(parseFhirDate("2026-05-01T10:30:00Z")).toBeGreaterThan(0);
  });

  it("returns zero for invalid FHIR timestamp", () => {
    expect(parseFhirDate("not-a-date")).toBe(0);
  });
});
