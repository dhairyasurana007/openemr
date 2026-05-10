import React from "react";
import { createRoot, type Root } from "react-dom/client";
import { act } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { AuthState, DashboardBootstrap } from "./auth";
import type { DashboardData } from "./fhir";

const initializeAuthMock = vi.fn<(config: DashboardBootstrap) => Promise<AuthState>>();
const fetchDashboardDataMock = vi.fn<(config: DashboardBootstrap, token: string | null) => Promise<DashboardData>>();

vi.mock("./auth", async () => {
  const actual = await vi.importActual<typeof import("./auth")>("./auth");
  return {
    ...actual,
    initializeAuth: initializeAuthMock,
  };
});

vi.mock("./fhir", async () => {
  const actual = await vi.importActual<typeof import("./fhir")>("./fhir");
  return {
    ...actual,
    fetchDashboardData: fetchDashboardDataMock,
  };
});

async function flushEffects(): Promise<void> {
  await act(async () => {
    await Promise.resolve();
  });
}

function bootstrapConfig(): DashboardBootstrap {
  return {
    webRoot: "/openemr",
    moduleWebPath: "/interface/modules/custom_modules/oe-module-patient-dashboard-react/public",
    pid: "1",
    patientId: "1",
    csrfToken: "csrf",
    apiBase: "/apis/default",
    timezone: "UTC",
    auth: {
      issuer: "https://issuer.example",
      clientId: "dashboard-client",
      scope: "openid profile fhirUser",
      redirectPath: "/dashboard/callback",
    },
  };
}

describe("dashboard app integration states", () => {
  let rootElement: HTMLDivElement;
  let root: Root;

  beforeEach(() => {
    initializeAuthMock.mockReset();
    fetchDashboardDataMock.mockReset();
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
    rootElement = document.createElement("div");
    root = createRoot(rootElement);
    (window as Window & { OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap }).OEMR_DASHBOARD_BOOTSTRAP = undefined;
    fetchDashboardDataMock.mockResolvedValue({
      header: { name: "Unknown", dob: "Unknown", sex: "Unknown", mrn: "Unknown", activeStatus: "Active" },
      allergies: [],
      problemList: [],
      medications: [],
      prescriptions: [],
      careTeam: [],
      vitals: [],
    });
  });

  afterEach(async () => {
    await act(async () => {
      root.unmount();
    });
    delete (window as Window & { OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap }).OEMR_DASHBOARD_BOOTSTRAP;
  });

  it("renders required card headings and populated data after auth", async () => {
    const { App } = await import("./main");
    (window as Window & { OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap }).OEMR_DASHBOARD_BOOTSTRAP = bootstrapConfig();

    initializeAuthMock.mockResolvedValue({ status: "ready", accessToken: "token-123" });
    fetchDashboardDataMock.mockResolvedValue({
      header: { name: "Taylor Smith", dob: "1980-01-01", sex: "female", mrn: "MRN-1", activeStatus: "Active" },
      allergies: [{ primary: "Peanut allergy", secondary: "Criticality: high" }],
      problemList: [{ primary: "Hypertension", secondary: "active" }],
      medications: [{ primary: "Aspirin", secondary: "active" }],
      prescriptions: [{ primary: "Lisinopril", secondary: "Authored: 2026-05-01" }],
      careTeam: [{ primary: "Dr. Jane", secondary: "Primary care" }],
      vitals: [{ primary: "Blood Pressure", secondary: "120 mmHg (2026-05-01)" }],
    });

    await act(async () => {
      root.render(<App />);
    });
    await flushEffects();
    await flushEffects();

    expect(rootElement.textContent).toContain("Modern Patient Dashboard");
    expect(rootElement.textContent).toContain("Allergies");
    expect(rootElement.textContent).toContain("Problem List");
    expect(rootElement.textContent).toContain("Medications");
    expect(rootElement.textContent).toContain("Prescriptions");
    expect(rootElement.textContent).toContain("Care Team");
    expect(rootElement.textContent).toContain("Vitals");
    expect(rootElement.textContent).toContain("Peanut allergy");
    expect(fetchDashboardDataMock).toHaveBeenCalledWith(expect.any(Object), "token-123");
  });

  it("shows empty state messaging when cards return no resources", async () => {
    const { App } = await import("./main");
    (window as Window & { OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap }).OEMR_DASHBOARD_BOOTSTRAP = bootstrapConfig();
    initializeAuthMock.mockResolvedValue({ status: "ready", accessToken: "token-123" });
    fetchDashboardDataMock.mockResolvedValue({
      header: { name: "Taylor Smith", dob: "1980-01-01", sex: "female", mrn: "MRN-1", activeStatus: "Active" },
      allergies: [],
      problemList: [],
      medications: [],
      prescriptions: [],
      careTeam: [],
      vitals: [],
    });

    await act(async () => {
      root.render(<App />);
    });
    await flushEffects();
    await flushEffects();

    expect(rootElement.textContent).toContain("No data available.");
  });

  it("shows expired-token relogin state when auth is ready without token", async () => {
    const { App } = await import("./main");
    (window as Window & { OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap }).OEMR_DASHBOARD_BOOTSTRAP = bootstrapConfig();
    initializeAuthMock.mockResolvedValue({
      status: "ready",
      accessToken: null,
      reason: "Redirecting to OIDC login",
    });

    await act(async () => {
      root.render(<App />);
    });
    await flushEffects();
    await flushEffects();

    expect(fetchDashboardDataMock).toHaveBeenCalledTimes(1);
    expect(fetchDashboardDataMock).toHaveBeenNthCalledWith(1, expect.any(Object), null);
    expect(rootElement.textContent).toContain("Redirecting to OIDC login");
  });
});
