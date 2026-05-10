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
```

Build output is written to:
- `interface/modules/custom_modules/oe-module-patient-dashboard-react/public/assets/dashboard.js`
- `interface/modules/custom_modules/oe-module-patient-dashboard-react/public/assets/dashboard.css`

## Notes

- This page is single-stack frontend mount (`#patient-dashboard-react-root`) with no legacy dashboard widgets embedded.
- Backend auth and API contracts are unchanged.
