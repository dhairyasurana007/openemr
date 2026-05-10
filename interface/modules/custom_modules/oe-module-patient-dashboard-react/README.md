# React Patient Dashboard Module (Scaffold)

This module scaffolds a React + TypeScript dashboard app inside the OpenEMR codebase.

## Route

Open from OpenEMR UI:
- `Modules -> Modern Patient Dashboard`
- Patient dashboard heading action: `Open Modern Dashboard`

Direct URL:
- `/interface/modules/custom_modules/oe-module-patient-dashboard-react/public/index.php?pid=<PATIENT_ID>`

## Frontend Commands

Run from `interface/modules/custom_modules/oe-module-patient-dashboard-react/frontend`.

```bash
npm install
npm run dev
npm run build
npm run lint
npm run test
```

Build output is written to:
- `interface/modules/custom_modules/oe-module-patient-dashboard-react/public/assets/dashboard.js`
- `interface/modules/custom_modules/oe-module-patient-dashboard-react/public/assets/dashboard.css`

## Notes

- This page is single-stack frontend mount (`#patient-dashboard-react-root`) with no legacy dashboard widgets embedded.
- Backend auth and API contracts are unchanged.

## OAuth2/OIDC Configuration

Set these environment variables in your OpenEMR runtime for the React dashboard route:

- `OEMR_DASHBOARD_OIDC_ISSUER` (required for auth): OIDC issuer/authority URL
- `OEMR_DASHBOARD_OIDC_CLIENT_ID` (required for auth): OAuth client ID
- `OEMR_DASHBOARD_OIDC_SCOPE` (optional): default `openid profile fhirUser`
- `OEMR_DASHBOARD_OIDC_REDIRECT_PATH` (optional): default `/interface/modules/custom_modules/oe-module-patient-dashboard-react/public/index.php`

Behavior:
- If required OIDC values are missing, the dashboard remains in auth-disabled mode.
- If access token is missing/expired, the frontend attempts silent renew and falls back to login redirect.

## Frontend Contract and Smoke Verification (Commit 6)

### Automated Frontend Tests

Run from:
- `interface/modules/custom_modules/oe-module-patient-dashboard-react/frontend`

Command:
- `npm run test`

Coverage focus:
- OIDC auth helper behavior (enabled/disabled bootstrap, redirect URI handling).
- Dashboard integration states:
  - required card headings render (Allergies, Problem List, Medications, Prescriptions, Care Team, Vitals)
  - populated-data rendering
  - empty-resource rendering (`No data available.`)
  - expired/missing token relogin state
- FHIR contract requests:
  - `APICSRFTOKEN` and `Authorization: Bearer <token>` headers for auth-gated calls
  - non-OK FHIR responses fail fast with explicit errors.

### Manual Smoke Flow

1. Start OpenEMR and sign in as a clinician user.
2. Open a patient chart with a known `pid`.
3. Navigate to `Modules -> Modern Patient Dashboard`.
4. Verify patient header fields render: Name, DOB, Sex, MRN, Status.
5. Verify cards render: Allergies, Problem List, Medications, Prescriptions, Care Team, Vitals.
6. Verify Vitals ordering is most-recent first using effective/issued timestamps.
7. Expired-token scenario: invalidate/expire the OIDC session and refresh the page; verify relogin redirect/retry path triggers and no stale protected data renders.
8. Missing-data scenario: use a patient with sparse data and verify card empty states show `No data available.` without page-level crashes.
