const root = document.getElementById("patient-dashboard-react-root");

if (root) {
  const config = window.OEMR_DASHBOARD_BOOTSTRAP || {};
  root.innerHTML = `
    <main style="max-width: 1100px; margin: 2rem auto; padding: 0 1rem; font-family: 'Segoe UI', sans-serif;">
      <section style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1rem;">
        <h1 style="font-size: 1.5rem; margin: 0 0 0.5rem;">Modern Patient Dashboard (React+TS Scaffold)</h1>
        <p style="margin: 0; color: #334155;">This page is now a dedicated single-stack frontend mount point.</p>
      </section>
      <section style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem 1.25rem;">
        <h2 style="font-size: 1.1rem; margin: 0 0 0.75rem;">Bootstrap Context</h2>
        <pre style="background: #0f172a; color: #e2e8f0; padding: 0.75rem; border-radius: 8px; overflow: auto;">${JSON.stringify(config, null, 2)}</pre>
      </section>
    </main>
  `;
}
