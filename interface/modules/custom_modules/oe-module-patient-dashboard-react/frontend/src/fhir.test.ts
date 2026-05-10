import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchDashboardData, parseFhirDate, patientDisplayName, patientMrn, resourceLabel } from "./fhir";

const testConfig = {
  webRoot: "/openemr",
  moduleWebPath: "/module",
  pid: "1",
  patientId: "1",
  csrfToken: "csrf-token",
  apiBase: "/apis/default",
  timezone: "UTC",
};

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

describe("fetchDashboardData contract", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("sends bearer token and csrf headers on all FHIR requests", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
      return {
        ok: true,
        json: async () => ({ entry: [] }),
      } as Response;
    });

    fetchMock.mockImplementationOnce(async () => {
      return {
        ok: true,
        json: async () => ({
          name: [{ given: ["Taylor"], family: "Smith" }],
          birthDate: "1980-01-01",
          gender: "female",
          active: true,
          identifier: [{ value: "MRN-123" }],
        }),
      } as Response;
    });

    await fetchDashboardData(testConfig, "token-123");

    expect(fetchMock).toHaveBeenCalledTimes(7);
    for (const call of fetchMock.mock.calls) {
      const init = call[1] as RequestInit;
      const headers = init.headers as Record<string, string>;
      expect(headers.APICSRFTOKEN).toBe("csrf-token");
      expect(headers.Authorization).toBe("Bearer token-123");
    }
  });

  it("throws for non-OK responses (covers auth-gated API failures)", async () => {
    vi.spyOn(globalThis, "fetch").mockResolvedValue({
      ok: false,
      status: 401,
      json: async () => ({}),
    } as Response);

    await expect(fetchDashboardData(testConfig, "expired-token")).rejects.toThrow("FHIR request failed (401)");
  });
});
