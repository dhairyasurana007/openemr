# Patient Dashboard Migration Defense (Week 2)

## Scope and Decision

This migration introduces a clinician-facing Modern Patient Dashboard using a single-stack React + TypeScript frontend module inside the existing OpenEMR codebase:

- Module path: `interface/modules/custom_modules/oe-module-patient-dashboard-react`
- Frontend app path: `interface/modules/custom_modules/oe-module-patient-dashboard-react/frontend`
- Runtime route: `/interface/modules/custom_modules/oe-module-patient-dashboard-react/public/index.php?pid=<PATIENT_ID>`

The migration scope is presentation-layer modernization for the required dashboard subset only. It is not a full replacement of all legacy dashboard pages.

## Why React + TypeScript

React + TypeScript was selected to improve delivery speed and maintenance quality for a data-dense clinician UI:

- Componentized rendering enables clear ownership of cards (Allergies, Problem List, Medications, Prescriptions, Care Team, Vitals).
- TypeScript reduces runtime defects in FHIR payload parsing and UI state handling by enforcing compile-time contracts.
- Vite-based tooling provides fast local iteration and predictable production builds for module assets.
- Modern test tooling (Vitest + jsdom) supports fast contract and integration-style tests around auth-gated data flows.

## Architecture Boundary

The migration is intentionally limited to frontend presentation and route wiring:

- A React app mounts into a dedicated page container (`#patient-dashboard-react-root`).
- Existing OpenEMR patient context (`pid`, `patientId`) is passed via bootstrap configuration from PHP.
- Data is read from existing OpenEMR FHIR API endpoints.
- OAuth2/OIDC is consumed from existing OpenEMR environment/runtime configuration.

No backend service topology changes are introduced by this migration.

## Explicit Non-Replacement Statement

This migration does **not** replace OpenEMR backend APIs or backend authentication services.

- Backend API contracts are preserved; the dashboard consumes existing FHIR endpoints.
- Backend auth behavior is preserved; the frontend uses configured OAuth2/OIDC values and token flows without changing backend auth implementation.
- No new backend auth protocol or API version was introduced for this dashboard work.

## Gains

- Single-stack modern UI on the migrated dashboard page (no mixed legacy widget embedding on that page).
- Clear loading/empty/error states per clinical card.
- FHIR-based card mapping with deterministic rendering paths.
- Auth-aware dashboard behavior with relogin path for missing/expired tokens.
- Added frontend contract/integration tests and smoke runbook to make verification reproducible.

## Tradeoffs and Risks

- Additional frontend build/test toolchain inside module (`npm`, `vite`, `vitest`) increases module maintenance surface.
- OIDC behavior depends on environment configuration correctness; misconfiguration degrades to auth-disabled or relogin states.
- FHIR resource shape variability can still require ongoing parser hardening as edge cases are discovered.
- Dual navigation period (legacy and modern views) may require short-term documentation/training overhead.

## Mitigations

- Keep migration scoped to presentation and avoid backend contract changes.
- Enforce required dashboard behavior with automated tests (auth-gated requests, card rendering, empty and expired-token scenarios).
- Maintain a documented smoke flow for clinician-path verification in a running OpenEMR environment.
- Preserve same-session navigation between legacy and modern contexts to reduce operational disruption.

## Acceptance Alignment

The implemented migration aligns with Week 2 expectations for this commit:

- Framework choice and rationale are documented (React + TypeScript).
- Migration gains and tradeoffs are explicitly stated.
- Presentation-layer-only boundary is explicit.
- Explicit statement included that backend APIs/auth were not replaced.
