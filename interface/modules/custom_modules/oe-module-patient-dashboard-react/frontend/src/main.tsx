import React from "react";
import { createRoot } from "react-dom/client";

type DashboardBootstrap = {
  webRoot: string;
  moduleWebPath: string;
  patientId: string;
  csrfToken: string;
  apiBase: string;
  timezone: string;
};

declare global {
  interface Window {
    OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap;
  }
}

function App(): React.JSX.Element {
  const config = window.OEMR_DASHBOARD_BOOTSTRAP;

  return (
    <main style={{ maxWidth: "1100px", margin: "2rem auto", padding: "0 1rem", fontFamily: "Segoe UI, sans-serif" }}>
      <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem", marginBottom: "1rem" }}>
        <h1 style={{ fontSize: "1.5rem", margin: "0 0 .5rem" }}>Modern Patient Dashboard (React + TypeScript)</h1>
        <p style={{ margin: 0, color: "#334155" }}>React mount is wired through OpenEMR module route.</p>
      </section>
      <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem" }}>
        <h2 style={{ fontSize: "1.1rem", margin: "0 0 .75rem" }}>Bootstrap Context</h2>
        <pre style={{ background: "#0f172a", color: "#e2e8f0", padding: ".75rem", borderRadius: "8px", overflow: "auto" }}>
          {JSON.stringify(config ?? {}, null, 2)}
        </pre>
      </section>
    </main>
  );
}

const target = document.getElementById("patient-dashboard-react-root");
if (target) {
  createRoot(target).render(<App />);
}
