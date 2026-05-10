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
