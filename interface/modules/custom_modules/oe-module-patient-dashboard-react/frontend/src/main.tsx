import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { AuthState, DashboardBootstrap, initializeAuth } from "./auth";

declare global {
  interface Window {
    OEMR_DASHBOARD_BOOTSTRAP?: DashboardBootstrap;
  }
}

function App(): React.JSX.Element {
  const config = window.OEMR_DASHBOARD_BOOTSTRAP;
  const [authState, setAuthState] = useState<AuthState>({ status: "disabled", accessToken: null });

  useEffect(() => {
    if (!config) {
      setAuthState({ status: "error", accessToken: null, reason: "Missing bootstrap configuration" });
      return;
    }

    void initializeAuth(config).then((result) => {
      setAuthState(result);
    });
  }, [config]);

  return (
    <main style={{ maxWidth: "1100px", margin: "2rem auto", padding: "0 1rem", fontFamily: "Segoe UI, sans-serif" }}>
      <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem", marginBottom: "1rem" }}>
        <h1 style={{ fontSize: "1.5rem", margin: "0 0 .5rem" }}>Modern Patient Dashboard (React + TypeScript)</h1>
        <p style={{ margin: 0, color: "#334155" }}>OAuth2/OIDC gate is active for this dashboard surface.</p>
      </section>
      <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem", marginBottom: "1rem" }}>
        <h2 style={{ fontSize: "1.1rem", margin: "0 0 .75rem" }}>Auth Status</h2>
        <p style={{ margin: 0 }}>
          State: <strong>{authState.status}</strong>
        </p>
        {authState.reason ? <p style={{ margin: ".5rem 0 0", color: "#475569" }}>{authState.reason}</p> : null}
      </section>
      <section style={{ background: "#fff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "1rem 1.25rem" }}>
        <h2 style={{ fontSize: "1.1rem", margin: "0 0 .75rem" }}>Bootstrap Context</h2>
        <pre style={{ background: "#0f172a", color: "#e2e8f0", padding: ".75rem", borderRadius: "8px", overflow: "auto" }}>
          {JSON.stringify(
            {
              ...(config ?? {}),
              auth: {
                enabled: authState.status !== "disabled",
                tokenPresent: Boolean(authState.accessToken),
              },
            },
            null,
            2
          )}
        </pre>
      </section>
    </main>
  );
}

const target = document.getElementById("patient-dashboard-react-root");
if (target) {
  createRoot(target).render(<App />);
}
