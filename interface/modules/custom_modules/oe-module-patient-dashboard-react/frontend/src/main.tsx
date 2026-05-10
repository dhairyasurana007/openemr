import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { AuthState, DashboardBootstrap, initializeAuth } from "./auth";
import { DashboardData, DashboardItem, fetchDashboardData } from "./fhir";

declare global {
  interface Window {
    OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap;
  }
}

type CardState = {
  status: "loading" | "ready" | "empty" | "error";
  items: DashboardItem[];
  reason?: string;
};

function emptyCardState(): CardState {
  return { status: "loading", items: [] };
}

function evaluateCardState(items: DashboardItem[]): CardState {
  if (items.length === 0) {
    return { status: "empty", items: [] };
  }
  return { status: "ready", items };
}

function setAllCardsError(
  reason: string,
  setters: Array<(state: CardState) => void>
): void {
  setters.forEach((setState) => {
    setState({ status: "error", items: [], reason });
  });
}

function FhirCard({ title, state }: { title: string; state: CardState }): React.JSX.Element {
  return (
    <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem" }}>
      <h2 style={{ fontSize: "1.1rem", margin: "0 0 .75rem" }}>{title}</h2>
      {state.status === "loading" ? <p style={{ margin: 0, color: "#475569" }}>Loading...</p> : null}
      {state.status === "error" ? <p style={{ margin: 0, color: "#b91c1c" }}>{state.reason ?? "Failed to load"}</p> : null}
      {state.status === "empty" ? <p style={{ margin: 0, color: "#475569" }}>No data available.</p> : null}
      {state.status === "ready" ? (
        <ul style={{ margin: 0, paddingLeft: "1.25rem" }}>
          {state.items.map((item) => (
            <li key={`${title}-${item.primary}-${item.secondary ?? ""}`} style={{ marginBottom: ".35rem" }}>
              <span>{item.primary}</span>
              {item.secondary ? <span style={{ color: "#475569" }}> ({item.secondary})</span> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

export function App(): React.JSX.Element {
  const config = window.OEMR_DASHBOARD_BOOTSTRAP;
  const legacyDashboardUrl = config ? `${config.webRoot}/interface/patient_file/summary/demographics.php?pid=${encodeURIComponent(config.pid)}` : "#";
  const modernDashboardUrl = config ? `${config.webRoot}/interface/modules/custom_modules/oe-module-patient-dashboard-react/public/index.php?pid=${encodeURIComponent(config.pid)}` : "#";
  const restoreSession = (): void => {
    (window.top as Window & { restoreSession?: () => void })?.restoreSession?.();
  };
  const [authState, setAuthState] = useState<AuthState>({ status: "disabled", accessToken: null });
  const [header, setHeader] = useState<DashboardData["header"] | null>(null);
  const [headerError, setHeaderError] = useState<string | null>(null);
  const [allergies, setAllergies] = useState<CardState>(emptyCardState());
  const [problemList, setProblemList] = useState<CardState>(emptyCardState());
  const [medications, setMedications] = useState<CardState>(emptyCardState());
  const [prescriptions, setPrescriptions] = useState<CardState>(emptyCardState());
  const [careTeam, setCareTeam] = useState<CardState>(emptyCardState());
  const [vitals, setVitals] = useState<CardState>(emptyCardState());

  useEffect(() => {
    if (!config) {
      setAuthState({ status: "error", accessToken: null, reason: "Missing bootstrap configuration" });
      return;
    }

    void initializeAuth(config).then((result) => {
      setAuthState(result);
    });
  }, [config]);

  useEffect(() => {
    const cardSetters = [setAllergies, setProblemList, setMedications, setPrescriptions, setCareTeam, setVitals];

    if (!config) {
      return;
    }

    if (authState.status === "error") {
      const reason = authState.reason ?? "Authentication failed";
      setHeader(null);
      setHeaderError(reason);
      setAllCardsError(reason, cardSetters);
      return;
    }

    if (authState.status !== "ready" && authState.status !== "disabled") {
      return;
    }

    if (authState.status === "ready" && config.auth?.issuer && !authState.accessToken) {
      const reason = authState.reason ?? "Waiting for sign-in...";
      setHeader(null);
      setHeaderError(reason);
      setAllCardsError(reason, cardSetters);
      return;
    }

    setAllergies(emptyCardState());
    setProblemList(emptyCardState());
    setMedications(emptyCardState());
    setPrescriptions(emptyCardState());
    setCareTeam(emptyCardState());
    setVitals(emptyCardState());
    setHeader(null);
    setHeaderError(null);

    void fetchDashboardData(config, authState.accessToken)
      .then((data) => {
        setHeader(data.header);
        setAllergies(evaluateCardState(data.allergies));
        setProblemList(evaluateCardState(data.problemList));
        setMedications(evaluateCardState(data.medications));
        setPrescriptions(evaluateCardState(data.prescriptions));
        setCareTeam(evaluateCardState(data.careTeam));
        setVitals(evaluateCardState(data.vitals));
      })
      .catch((error: unknown) => {
        const reason = error instanceof Error ? error.message : "Failed to load dashboard data";
        setHeaderError(reason);
        setAllCardsError(reason, cardSetters);
      });
  }, [authState, config]);

  return (
    <main style={{ maxWidth: "1100px", margin: "2rem auto", padding: "0 1rem", fontFamily: "Segoe UI, sans-serif" }}>
      <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: ".75rem" }}>
        <div style={{ display: "inline-flex", border: "1px solid #94a3b8", borderRadius: "6px", overflow: "hidden" }} role="group" aria-label="Dashboard View Toggle">
          <a
            href={legacyDashboardUrl}
            onClick={restoreSession}
            style={{ padding: ".4rem .75rem", textDecoration: "none", color: "#0f172a", background: "#fff", borderRight: "1px solid #94a3b8" }}
          >
            Legacy
          </a>
          <a
            href={modernDashboardUrl}
            onClick={restoreSession}
            style={{ padding: ".4rem .75rem", textDecoration: "none", color: "#fff", background: "#3b82f6", fontWeight: 600 }}
          >
            Modern
          </a>
        </div>
      </div>
      <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem", marginBottom: "1rem" }}>
        <h1 style={{ fontSize: "1.5rem", margin: "0 0 .5rem" }}>Modern Patient Dashboard</h1>
        {headerError ? <p style={{ margin: 0, color: "#b91c1c" }}>{headerError}</p> : null}
        {!header && !headerError ? <p style={{ margin: 0, color: "#475569" }}>Loading patient header...</p> : null}
        {header ? (
          <dl style={{ margin: ".75rem 0 0", display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: ".5rem 1rem" }}>
            <div>
              <dt style={{ fontWeight: 600 }}>Name</dt>
              <dd style={{ margin: 0 }}>{header.name}</dd>
            </div>
            <div>
              <dt style={{ fontWeight: 600 }}>DOB</dt>
              <dd style={{ margin: 0 }}>{header.dob}</dd>
            </div>
            <div>
              <dt style={{ fontWeight: 600 }}>Sex</dt>
              <dd style={{ margin: 0 }}>{header.sex}</dd>
            </div>
            <div>
              <dt style={{ fontWeight: 600 }}>MRN</dt>
              <dd style={{ margin: 0 }}>{header.mrn}</dd>
            </div>
            <div>
              <dt style={{ fontWeight: 600 }}>Status</dt>
              <dd style={{ margin: 0 }}>{header.activeStatus}</dd>
            </div>
          </dl>
        ) : null}
      </section>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(270px, 1fr))", gap: "1rem" }}>
        <FhirCard title="Allergies" state={allergies} />
        <FhirCard title="Problem List" state={problemList} />
        <FhirCard title="Medications" state={medications} />
        <FhirCard title="Prescriptions" state={prescriptions} />
        <FhirCard title="Care Team" state={careTeam} />
        <FhirCard title="Vitals" state={vitals} />
      </div>
    </main>
  );
}

const target = document.getElementById("patient-dashboard-react-root");
if (target) {
  createRoot(target).render(<App />);
}
