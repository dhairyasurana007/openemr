# ARCHITECTURE.md — Clinical Co-Pilot

## Summary

Clinical Co-Pilot is an agent built into OpenEMR for family medicine physicians in a small-to-mid clinic. This document assumes production use in clinics with about 3-8 physicians, with each physician seeing about 20 patients per day (roughly 60-160 patients/day total).

It supports three main use cases:
- **Pre-visit briefing:** before the physician enters the room, show a short summary based on nurse intake and recent chart history.
- **In-room follow-up question:** during the visit, answer pointed chart questions quickly (for example, lab trends, current dose, referral status).
- **Critical flag surfacing:** highlight high-risk items such as possible medication interactions, unaddressed abnormal labs, and overdue preventive care.

Production deployment runs on **AWS** (BAA-backed account) in a private VPC with HTTPS-only ingress. The default production footprint is **6 containers**:
- `openemr-web` (PHP/Laminas app)
- `openemr-mysql` (clinical database)
- `copilot-agent` (Python/FastAPI orchestration service)
- `nginx` (reverse proxy/TLS termination)
- `langfuse` (LLM observability UI/API)
- `langfuse-postgres` (Langfuse metadata store)

Redis is added as a seventh container when scaling to multi-instance agent replicas or queued pre-generation jobs.


Stack choices were made to fit OpenEMR realities and clinical risk constraints:
- **OpenEMR Laminas/Zend custom module (UI integration):** chosen to mount the panel inside the existing encounter workflow without forking core OpenEMR screens. This keeps deployment and upgrades cleaner while preserving native auth/session context.
- **Python + FastAPI agent service (orchestration layer):** chosen for mature LLM tooling, clean async request handling, and quick iteration on retrieval/verification logic. Keeping the agent as a separate service also isolates AI complexity from core OpenEMR PHP code.
- **OpenEMR REST API + service-layer access (data retrieval):** chosen over ad-hoc SQL so retrieval uses established OpenEMR domain paths, reducing schema-coupling risk and helping enforce consistent record filtering and structure.
- **Two-stage verification (pre-LLM context bounds + post-LLM source checks):** chosen to minimize hallucination risk by constraining what the model can claim and then auditing response claims against retrieved evidence.
- **Claude Haiku/Sonnet model path (synthesis):** chosen for strong summarization and instruction-following quality, with model tiering to balance latency/cost (faster path for routine synthesis, stronger path for harder Q&A).
- **Langfuse self-hosted + OpenEMR `ai_audit_log` (observability/audit):** chosen to maintain operational visibility (latency, tool traces, failures, token usage) while keeping PHI governance tighter and audit trails inside controlled infrastructure.

Design priorities:
- Fast retrieval over broad chat behavior
- Strict patient scoping and authorization checks
- Data-only responses (no diagnosis/treatment recommendations)
- Minimal PHI egress and auditable traces

---

## 1. User Workflow

**User:** family physician in 15-minute visits, ~20 patients/day.

**Use cases:**
1. **Pre-visit briefing (UC1)**  
   Starts when nurse intake is complete (chief complaint, vitals, med updates).  
   Physician sees the briefing on encounter open.
2. **In-room Q&A (UC2)**  
   Physician asks a pointed chart question and gets a direct, grounded answer.
3. **Critical flag surfacing (UC3)**  
   Agent highlights drug interactions, unaddressed abnormal labs, and overdue preventive care.

---

## 2. Use Case Diagrams

### UC1 — Pre-Visit Briefing

```
  [Nurse completes intake]
  (chief complaint, vitals, med updates)
                |
                v
  +-----------------------------+
  | Intake Saved in OpenEMR     |
  +-----------------------------+
                |
                | intake-complete trigger
                v
  +-----------------------------+
  | Agent Service               |
  | (Python + FastAPI)          |
  | starts pre-generation       |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Auth Guard                  |
  | verify physician-patient    |
  +-----------------------------+
                |
                v
  +-----------------------------+      +-----------------------------+
  | Tool Layer                  | ---> | OpenEMR REST API + MySQL    |
  | (PHP bridge + services)     | <--- | (read-only retrieval)       |
  +-----------------------------+      +-----------------------------+
                |
                v
  +-----------------------------+
  | Verification Layer          |
  | (rule checks + source match)|
  +-----------------------------+
                |
                v
  +-----------------------------+
  | AI Synthesis Engine         |
  | (Claude Haiku/Sonnet API)   |
  | creates pre-visit summary   |
  +-----------------------------+
                |
                v
  [Physician opens encounter]
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | (OpenEMR Laminas module)    |
  | show briefing (5-10s)       |
  +-----------------------------+
```

### UC2 — In-Room Q&A

```
  [Physician asks pointed question]
  (example: "A1C trend last 18 months?")
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | (OpenEMR Laminas module)    |
  | send Q&A request            |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Agent Q&A Path              |
  | (Python + FastAPI)          |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Auth Guard                  |
  | verify physician-patient    |
  +-----------------------------+
                |
                v
  +-----------------------------+      +-----------------------------+
  | Tool Layer                  | ---> | OpenEMR REST API + MySQL    |
  | (PHP bridge + services)     | <--- | (read-only retrieval)       |
  +-----------------------------+      +-----------------------------+
                |
                v
  +-----------------------------+
  | Verification Layer          |
  | (rule checks + source match)|
  +-----------------------------+
                |
                v
  +-----------------------------+
  | AI Synthesis Engine         |
  | (Claude Haiku/Sonnet API)   |
  | direct grounded answer      |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | (OpenEMR Laminas module)    |
  | stream answer (<8s)         |
  +-----------------------------+
```

### UC3 — Critical Flag Surfacing

```
  [Trigger]
  - Included in pre-visit briefing, or
  - Requested on demand by physician
                |
                v
  +-----------------------------+
  | Agent Service               |
  | (Python + FastAPI)          |
  | flag detection path         |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Auth Guard                  |
  | verify physician-patient    |
  +-----------------------------+
                |
                v
  +-----------------------------+      +-----------------------------+
  | Tool Layer                  | ---> | OpenEMR REST API + MySQL    |
  | (PHP bridge + services)     | <--- | (read-only retrieval)       |
  +-----------------------------+      +-----------------------------+
                |
                v
  +-----------------------------+
  | Verification Layer          |
  | (rule checks + source match)|
  | evidence required per flag  |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Flag Output                 |
  | interactions, unaddressed   |
  | abnormal labs, overdue care |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | (OpenEMR Laminas module)    |
  | display with source context |
  +-----------------------------+
```

---

## 3. Architecture Components

1. **OpenEMR Module + Panel**  
   Embeds the UI in encounter view and carries authenticated session context.
  (Laminas/Zend custom module)
2. **Agent Service**  
   Runs retrieval orchestration, guardrails, synthesis, and response shaping.
  (Python + FastAPI)

3. **Auth Guard**  
   Verifies physician-patient access before data fetch.
4. **Tool Layer**  
   Uses existing OpenEMR service paths and returns structured records.
  (OpenEMR REST endpoints, PHP bridge, service classes)
5. **Verification Layer**  
   Enforces claim grounding before physician-visible output.
  (pre-LLM context bounding + post-LLM source checks)
6. **Audit/Observability**  
   Records traces, latency, and outcomes without PHI field values.
  (Langfuse self-hosted + OpenEMR ai_audit_log)


---

## 4. Guardrails

- **Read-only boundary:** no chart writes, no messaging, no task creation.
- **Data-only output:** no treatment recommendations or interpretation.
- **Bounded context:** claims must come from retrieved records.
- **Failure transparency:** tool failures are disclosed; data is not silently omitted.
- **Scoped access:** every request is bound to physician + patient context.
- **Secure-by-default transport/session:** TLS-only ingress, secure cookies, strict session handling, and short-lived service tokens.
- **Least-privilege runtime:** agent service account is read-only for clinical retrieval paths and cannot write clinical records.

---

## 5. Data Retrieval Contract

Minimum retrieval domains for each briefing/Q&A response:
- Patient summary (demographics, active problems, allergies)
- Active medications (name, dose/frequency, prescriber/time context)
- Recent labs (values, ranges, abnormal flags, dates)
- Recent encounter context (chief complaint + recent note excerpts)

All responses include source linkage so physicians can verify in chart.

---

## 6. Performance Targets

- **Pre-visit briefing (UC1):** visible within 5 seconds of encounter open  
  (with pre-generation kicked off at intake completion)
- **In-room Q&A (UC2):** answer within 8 seconds
- **Flag surfacing (UC3):** included in briefing and available on demand

If a dependency exceeds budget, return partial output with explicit gap disclosure.

---

## 7. PHI, Audit, and Safety

- PHI egress is controlled and minimized.
- BAA with model provider is required before production PHI use.
- Traces store metadata (timing, tool path, verification status), not PHI values.
- Audit log records request metadata and response integrity hash.
- Session context is isolated per physician-patient encounter.

---

## 8. Production Deployment and Operations

- **Environment model:** separate staging and production environments with config parity; production runs only with approved PHI controls.
- **Runtime topology:** OpenEMR app, MySQL, agent service, and observability stack run as isolated services behind HTTPS with private network boundaries.
- **Reliability:** health checks, restart policies, and rollback-ready versioned deploys are required for every release.
- **Data protection:** encryption in transit and at rest, routine backups, and tested restore procedures are mandatory.
- **Secrets management:** API keys and credentials are managed through a secrets manager and rotated on a defined schedule.
- **Operational visibility:** production alerts cover error rate, latency SLO drift, tool failures, and authorization failures.

---

## 9. Risks and Mitigations

1. **Authorization bypass risk in core route-skip logic**
   - **Audit signal:** route-skip matching can over-match and bypass checks.
   - **Mitigation:** deploy fixed route-matching logic, add regression tests for allow/deny route patterns, and block deployment if auth tests fail.

2. **Patient-scope enforcement gap in bearer-token flows**
   - **Audit signal:** bearer-token patient check is effectively stubbed.
   - **Mitigation:** enforce explicit physician-to-patient validation in agent auth guard plus OpenEMR API layer; require both checks to pass (defense in depth).

3. **Fail-open ACL behavior under configuration drift**
   - **Audit signal:** empty ACO specs can allow access.
   - **Mitigation:** enforce fail-closed policy for missing ACL metadata, add startup config validation, and alert on ACL rule load errors.

4. **Session/token theft risk (XSS + weak cookie defaults)**
   - **Audit signal:** legacy cookie defaults increase blast radius.
   - **Mitigation:** production cookie policy: `Secure`, `HttpOnly`, `SameSite=Strict` where possible; short session TTL; CSP + output encoding hardening; TLS 1.2+ only.

5. **PHI leakage through logging**
   - **Audit signal:** API payload logging and SQL bind/comment logs can capture PHI.
   - **Mitigation:** disable raw request/response payload logging in production, redact sensitive fields before logging, and restrict audit detail to metadata plus integrity hash.

6. **Weak key-management separation**
   - **Audit signal:** encryption key material may be database-resident.
   - **Mitigation:** move encryption keys to KMS/HSM-backed secret storage, rotate keys on schedule, and enforce separation of duties between DB admins and key admins.

7. **External module/data-egress surface**
   - **Audit signal:** custom connectors (fax/SMS/telehealth) increase exfiltration paths.
   - **Mitigation:** explicit egress allowlist, per-module data-sharing policy, outbound audit logs, and periodic connector security review before enablement.

8. **Data-quality drift leading to unsafe summaries**
   - **Audit signal:** soft-delete flags, stale records, and weak FK enforcement can produce inconsistent output.
   - **Mitigation:** strict active/deleted filtering in every tool, recency bounds, source citation requirements, and refusal when evidence is incomplete.

9. **Compliance gaps in retention and incident response**
   - **Audit signal:** retention/disposal and breach-notification workflows are underdefined.
   - **Mitigation:** codified retention schedule with automated purge jobs, legal-hold controls, and a tested breach runbook with evidence package generation.

10. **Credential exposure during MFA transitions**
    - **Audit signal:** plaintext password state may be carried across MFA steps.
    - **Mitigation:** remove cleartext password propagation, use one-time challenge state, add MFA throttling/lockout telemetry, and alert on repeated failures.

---

## 10. Production Scope Limits

- No autonomous actions or order entry
- No longitudinal care-plan generation
- No inferred facts outside retrieved chart evidence
- Session memory is encounter-scoped and short-lived
- No silent fallback to unchecked or uncited model output

---

## 11. Preconditions for Real Clinical Use

1. Patient-scoping and authorization hardening are deployed and validated.
2. PHI governance controls (including BAA) are in place.
3. Audit logging and encryption at rest are verified.
4. Adversarial and missing-data evals pass with no unsafe failures.
5. Physician review confirms outputs are accurate and useful in real workflow.
