# ARCHITECTURE.md — Clinical Co-Pilot

## Summary

Clinical Co-Pilot is an agent built into OpenEMR for **family physicians** in **small-to-mid practices** seeing about **20 patients per day** in **15-minute slots**, using OpenEMR as their primary EHR. This document assumes production use in clinics with about 3–8 physicians (roughly 60–160 patients/day total).

**Agent scope (see [`USERS.md`](USERS.md)):** the agent is **informative only** — it summarizes and surfaces facts from the record (and, where applicable, schedule or task context). It does **not** write to the chart, orders, problem or medication lists, or patient messages, and does not send communications. It gives **no recommendations**: it does not advise what to prescribe, order, refer, or document, nor operational “what to do next.” It presents what is on file; **plain-language drafts** appear only where a use case explicitly allows them (**UC5**), for the physician to edit. Individual use cases add tighter limits.

**Clinical workflow the architecture supports:** start-of-day and post-lunch **schedule-wide** scans (**UC1**, **UC6**); **nurse pre-visit intake** documented in OpenEMR (vitals, meds, chief complaint, symptoms); **~90 seconds between patients** when the physician opens the encounter and receives the **pre-visit briefing** (**UC2**); **in-room** chart lookups (**UC4**); optional **post-visit patient message draft** from the official record (**UC5**); end-of-day **no-show / missed-appointment sweep** (**UC7**). **UC3** (critical flags) is bundled with **UC2** or available on demand.

It supports **seven** use cases, numbered **chronologically** by typical clinical day in [`USERS.md`](USERS.md). In brief:
- **UC1 — Early-morning day summary:** shallow, schedule-wide orientation before the column starts (~20 slots, wide and shallow factual lines per slot).
- **UC2 — Pre-visit briefing:** on encounter open, synthesis of **today’s nurse intake** and **chart history** in a scannable shape (chief-complaint–led, not a generic dump).
- **UC3 — Critical flag surfacing:** possible medication interactions, unaddressed abnormal labs, overdue preventive care, long-pending referrals; part of UC2, UC1/UC6 “scan hints” when the product surfaces them, or on demand.
- **UC4 — In-room follow-up question:** during the visit, pointed chart questions (lab trends, dose, referral status) — **values and facts**, not clinical interpretation.
- **UC5 — Post-appointment patient message:** optional patient-facing **draft** grounded only in **documented** encounter and associated structured data; physician reviews, edits, and sends; optional physician-only “chart recap” line for verification.
- **UC6 — Post-lunch schedule summary:** same **shape** as UC1 for **remaining** (and add-on) slots; optional factual session counts when derivable from schedule/EHR without inference.
- **UC7 — No-show sweep:** compact, facts-only blocks per missed or same-day-cancelled slot the physician did not see; time-sensitive chart signals only — **no** callback or workup recommendations.

The **diagrams** in **Section 2** below cover **UC2**, **UC3**, and **UC4** (the encounter-scoped retrieval paths). **UC1**, **UC6**, and **UC7** reuse the same agent stack with **schedule- or list-scoped** retrieval and stricter “wide and shallow” response shaping; **UC5** reuses encounter-scoped retrieval with **documentation-bound** synthesis and no direct patient send. **Section 3** defines **seven JSON-schema tools** the LLM calls for retrieval (no deterministic application-level tool chain)—schedule slots, a **calendar** window (events + category metadata), and five patient-chart domains.

Production deployment runs on **AWS** (BAA-backed account) in a private VPC with HTTPS-only ingress. The default **in-VPC** footprint is **4 containers**:
- `openemr-web` (PHP/Laminas app)
- `openemr-mysql` (clinical database)
- `copilot-agent` (Python/FastAPI HTTP service with LangChain orchestration)
- `nginx` (reverse proxy/TLS termination)

**LangSmith** (managed, LangChain-native tracing and evals) is used for LLM observability; it is **not** part of that container count—the agent sends **metadata-only** traces (no PHI payloads) to LangSmith per your configuration and contractual posture.

Redis is added as a **fifth** container when scaling to multi-instance agent replicas or queued pre-generation jobs (for example warming **UC2** after intake completion).


Stack choices were made to fit OpenEMR realities and clinical risk constraints:
- **OpenEMR Laminas/Zend custom module (UI integration):** chosen to mount the panel inside the existing encounter workflow without forking core OpenEMR screens. This keeps deployment and upgrades cleaner while preserving native auth/session context. Schedule/list surfaces for **UC1**, **UC6**, and **UC7** attach to the same module patterns with **schedule- or day-scoped** requests instead of a single open encounter where appropriate.
- **Python + FastAPI agent service with LangChain (orchestration layer):** LangChain supplies chains, tool binding, and structured model calls; FastAPI exposes a small async HTTP surface. Together they support rapid iteration on retrieval and verification while isolating AI complexity from core OpenEMR PHP code.
- **OpenEMR REST API + service-layer access (data retrieval):** chosen over ad-hoc SQL so retrieval uses established OpenEMR domain paths, reducing schema-coupling risk and helping enforce consistent record filtering and structure.
- **Two-stage verification (pre-LLM context bounds + post-LLM source checks):** chosen to minimize hallucination risk by constraining what the model can claim and then auditing response claims against retrieved evidence.
- **OpenRouter (synthesis and Q&A):** chosen as a single integration point to multiple vetted models via OpenRouter’s API, with LangChain’s chat model bindings. Model IDs and policies (latency, cost, zero-data retention where required) are selected explicitly—for example faster models for routine briefing and stronger models for harder Q&A.
- **LangSmith + OpenEMR `ai_audit_log` (observability/audit):** LangSmith integrates directly with LangChain for runs, tool spans, latency, and token usage. Clinical audit evidence remains in OpenEMR; LangSmith must receive only non-PHI trace fields, with enterprise agreements and project settings aligned to your compliance requirements.

Design priorities:
- Fast retrieval over broad chat behavior
- **LLM-chosen tool calls:** seven read-only, schema-bound tools (Section 3); the model decides which tools and arguments to use per request—application code does not hard-code retrieval order.
- Strict patient scoping and authorization checks (and **schedule-slot** scoping for day-wide operations)
- Data-only responses (no diagnosis/treatment recommendations; **UC4** returns facts, not interpretation)
- Minimal PHI egress and auditable traces

---

## 1. User Workflow

**Primary user:** family physician in 15-minute visits, ~20 patients/day.

**Care team context:** a **nurse** completes **pre-visit intake** before the physician enters the room (vitals, medication list updates, chief complaint, reason for visit, new symptoms). That documentation lives in OpenEMR and is a **first-class input** to **UC2** alongside longitudinal chart data.

**Agent role:** informative only; no chart writes; no clinical or operational recommendations; no autonomous messaging (**UC5** supplies **drafts** only; the physician sends through normal workflow).

**Use cases** (same order as `USERS.md`):
1. **Early-morning day summary (UC1)** — physician opens **today’s** (or next session’s) schedule; wide, shallow **factual** lines per slot (time, patient id as on schedule, new vs established when on file, reason/chief complaint/visit type when documented, light chart hints such as critical results on file, referral pending vs resulted, “no same-day intake yet” when detectable). **Not** visit order, staffing, or “who to prioritize.” Target latency: full day ~**20 seconds**.
2. **Pre-visit briefing (UC2)** — physician opens the patient encounter; briefing must land in **~5 seconds**, organized around **today’s chief complaint** (e.g. BP recheck → lead with BP history, antihypertensives, relevant labs). Includes nurse intake (vitals with notable change vs last visit, med changes), top active problems, recent abnormal labs, short last-visit summary, open care gaps. **No** “consider adjusting dose”–style advice.
3. **Critical flag surfacing (UC3)** — drug–drug or drug–condition interactions, abnormal labs not clearly addressed in the last note, overdue preventive care, referrals without result after ~60+ days; **context per flag** (which meds, which condition, why it matters). Part of **UC2**, optionally echoed in **UC1**/**UC6** scan mode, or on demand (on-demand path follows **UC4**-class latency where applicable).
4. **In-room Q&A (UC4)** — pointed question during an active encounter; **direct answer** from the record in **under 8 seconds**; **no** interpretation of results—return values and status, physician interprets.
5. **Post-appointment patient message (UC5)** — after the visit is documented, optional **plain-language draft** for the patient, grounded **only** in what is documented for **this** visit (note, assessment/plan, today’s orders/referrals, return instructions in chart). Optional one-line **physician-only** chart recap for verification. Draft in **~10 seconds**; agent does **not** send or choose channel; if the note is empty or contradictory, surface that briefly instead of inventing content.
6. **Post-lunch schedule summary (UC6)** — same **pattern** as **UC1** but scoped to **remaining** afternoon slots (and add-ons); optionally a single factual session line (counts from schedule/EHR only). Morning seen patients are not re-summarized in depth unless the user asks for a **full-day** refresh. Target: remaining half-day ~**15 seconds**.
7. **No-show sweep (UC7)** — end of day or on-demand list of **missed** or **same-day-cancelled** slots the physician did not see; compact blocks (overdue prevention, unresolved referrals, unaddressed abnormal labs, briefing-equivalent flags). Scannable minimal lines when nothing time-sensitive. Full list ~**15 seconds**; per-patient drill-down, if offered, aligns with **UC4** timing.

---

## 2. Use Case Diagrams

### UC1 / UC6 — Schedule-Wide Summary (Day Start or Post-Lunch)

```
  [Physician opens today's schedule]
  (whole column UC1, or "remaining" window UC6)
                |
                v
  +-----------------------------+
  | Co-Pilot Panel / Schedule   |
  | (OpenEMR Laminas module)    |
  | request day or remainder    |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Agent Schedule Path         |
  | (FastAPI + LangChain)       |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Auth Guard                  |
  | verify physician + schedule |
  | access (per slot / patient) |
  +-----------------------------+
                |
                v
  +-----------------------------+      +-----------------------------+
  | Tool Layer                  | ---> | OpenEMR REST API + MySQL    |
  | schedule + per-chart facts  | <--- | (read-only retrieval)       |
  +-----------------------------+      +-----------------------------+
                |
                v
  +-----------------------------+
  | Verification Layer          |
  | (facts only; no inference   |
  |  for "priority" or actions) |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Shallow Line Synthesis      |
  | (LangChain + OpenRouter)    |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | scannable list (SLO: UC1    |
  | 20s full day / UC6 15s rest)|
  +-----------------------------+
```

### UC2 — Pre-Visit Briefing

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
  | (FastAPI + LangChain)       |
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
  | (LangChain + OpenRouter)    |
  | chief-complaint-led summary |
  +-----------------------------+
                |
                v
  [Physician opens encounter]
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | (OpenEMR Laminas module)    |
  | show briefing (5s SLO)      |
  +-----------------------------+
```

### UC3 — Critical Flag Surfacing

```
  [Trigger]
  - Included in UC2 pre-visit briefing, or
  - Requested on demand by physician
                |
                v
  +-----------------------------+
  | Agent Service               |
  | (FastAPI + LangChain)       |
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

### UC4 — In-Room Q&A

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
  | (FastAPI + LangChain)       |
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
  | (LangChain + OpenRouter)    |
  | direct grounded answer      |
  | (facts; no interpretation)  |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | (OpenEMR Laminas module)    |
  | stream answer (8s SLO)      |
  +-----------------------------+
```

### UC5 — Post-Appointment Patient Message (Draft)

```
  [Physician requests patient message draft]
  (visit documented / orders reflect intent)
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | encounter-scoped request    |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Agent Draft Path            |
  | (FastAPI + LangChain)       |
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
  | today's note + structured   | <--- | visit data only (read-only) |
  | visit artifacts             |      +-----------------------------+
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Verification Layer          |
  | no claims outside encounter |
  | doc; refuse if empty/conflict|
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Patient-Facing Draft        |
  | + optional physician recap  |
  +-----------------------------+
                |
                v
  [Physician edits and sends     |
   via standard OpenEMR flow]   |
```

### UC7 — No-Show Sweep

```
  [End of day or physician selects missed list]
                |
                v
  +-----------------------------+
  | Co-Pilot Panel              |
  | list of no-shows / cancels  |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Agent List Path             |
  | (reuse UC2/UC3 retrieval    |
  |  shaping, per patient id)   |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Auth Guard + Tool Layer     |
  | (same read-only contract)   |
  +-----------------------------+
                |
                v
  +-----------------------------+
  | Compact factual blocks      |
  | (no callback / workup advice)|
  +-----------------------------+
```

---

## 3. Architecture Components

1. **OpenEMR Module + Panel**  
   Embeds the UI in **encounter view** and **schedule / day-list** contexts where **UC1**, **UC6**, **UC7** run; carries authenticated session context.  
  (Laminas/Zend custom module)
2. **Agent Service**  
   Runs retrieval orchestration, guardrails, synthesis, and response shaping for **encounter-scoped** and **schedule/list-scoped** requests.  
  (Python + FastAPI + LangChain; LLM calls via OpenRouter)

3. **Auth Guard**  
   Verifies physician–patient access for encounter paths; verifies **physician access to each scheduled slot / patient** (and optional facility scope) for day-wide operations.
4. **Tool Layer**  
   Uses existing OpenEMR service paths and returns structured records — **schedule and intake state** for shallow summaries, **longitudinal chart** for briefings and sweeps. Exposes **schema-bound tools** the LLM invokes directly (see **LLM-callable retrieval tools** below); orchestration is **model-driven**, not a fixed application-level tool chain.  
  (OpenEMR REST endpoints, PHP bridge, service classes)
5. **Verification Layer**  
   Enforces claim grounding before physician-visible output; **UC5** additionally enforces “only documented visit content”; **UC1**/**UC6**/**UC7** reject inferred prioritization or actions not present in source data.  
  (pre-LLM context bounding + post-LLM source checks)
6. **Audit/Observability**  
   Records traces, latency, and outcomes without PHI field values.  
  (LangSmith + OpenEMR ai_audit_log)

### LLM-callable retrieval tools

The agent registers **seven** read-only tools (each with a **JSON Schema** or equivalent function-calling contract, e.g. LangChain `StructuredTool`). The **LLM decides** which tools to call, with what arguments, and in what order; application code must **not** encode a deterministic pipeline such as “always run tools 1→2→3 before synthesis.” Rule-based **post-hoc verification** (and optional non-chain helpers that run **after** tool results return, e.g. interaction highlighting) is allowed; **retrieval sequencing** stays in the model.

Returns should use **stable, typed shapes** (arrays of objects with fixed keys) so the **Verification Layer** can match physician-visible claims to tool JSON without scraping free text.

| Tool | Primary use cases | Schema inputs (representative) | Returns (representative) |
|------|-------------------|-------------------------------|---------------------------|
| `list_schedule_slots` | UC1, UC6; seeds UC7 when the client passes a no-show list | `date`; optional `time_start` / `time_end` or enum `window` (`full_day`, `remainder_after`, …); optional `facility_id` | Slots: time, patient id, display identifier, visit type / reason when on schedule, slot status (e.g. scheduled / completed / no-show) |
| `get_calendar` | UC1, UC6; calendar-oriented questions across a **date range** (events + category metadata), alongside or instead of a single-day slot list | `start_date`; optional `end_date` (defaults to `start_date`); optional `calendar_id` (appointment category); optional `facility_id` | **Calendars:** category rows visible in scope; **events:** timed rows derived from the appointment backend for the window (same underlying schedule domain as slots, shaped for calendar views) |
| `get_patient_core_profile` | UC2 baseline; shallow orientation before deeper pulls | `patient_id`; optional `encounter_id` for scoping | Demographics, active problems (structured), allergies |
| `get_medication_list` | UC2 med reconciliation; UC3 interaction context; UC4 dose / interaction questions | `patient_id`; optional `encounter_id`; optional `since_date` or `compare_to_encounter_id` when the API supports deltas | Active meds: drug, dose, route, frequency, status, effective dates; prescriber when policy allows |
| `get_observations` | UC2 vitals and labs; UC3 unaddressed abnormals; UC4 trends (e.g. A1c) | `patient_id`; `categories[]` (e.g. `vital`, `laboratory`, `imaging_result`); optional `codes[]`; `from_date`, `to_date`; `limit`; `sort` | Time-stamped observations: value, unit, reference range / abnormal flag when available |
| `get_encounters_and_notes` | UC2 chief complaint / last visit; UC3 “addressed in note”; UC5 encounter grounding | `patient_id`; optional `encounter_id` (single visit); optional `limit` / `before_date`; optional `sections[]` (e.g. chief complaint, assessment/plan, HPI, chunked full text) | Encounter metadata and requested note sections |
| `get_referrals_orders_care_gaps` | UC2 open items; UC3 long-pending referral; UC4 referral result status; UC7 time-sensitive sweep | `patient_id`; optional status filters (e.g. `pending`, `completed`); optional `older_than_days` | Referrals / orders: ids, types, ordered date, status, result-on-file flags; structured care-gap / preventive overdue signals when exposed by the bridge |

**Optional split:** if latency tuning for **UC4** requires narrower fetches, replace `get_observations` with two tools — `get_vitals` and `get_laboratory_results` — using the same auth and typing rules (**eight** tools total including `get_calendar` and the other schedule/chart tools).

**Auth:** every tool execution runs after the **Auth Guard** resolves **physician + patient** (and, for `list_schedule_slots` and `get_calendar`, **schedule / facility** scope). Tools never accept raw SQL from the model.

---

## 4. Guardrails

- **Read-only boundary:** no chart writes, no messaging send, no task creation.
- **Informative-only / no recommendations:** no treatment, referral, documentation, or operational “what to do next” advice; **UC1**/**UC6** must not suggest visit order or “who to worry about first.”
- **Data-only output:** no treatment recommendations; **UC4** returns values and statuses — **no** clinical interpretation of results.
- **Bounded context:** claims must come from retrieved records; **UC5** must not add instructions, diagnoses, med changes, or follow-up **not** present in encounter documentation; do not minimize red-flag symptoms or promise outcomes.
- **Failure transparency:** tool failures are disclosed; data is not silently omitted; empty or contradictory notes for **UC5** are reported briefly instead of hallucinating visit content.
- **Scoped access:** every request is bound to physician + patient context, or physician + **explicit schedule/list** scope for day-wide features.
- **Secure-by-default transport/session:** TLS-only ingress, secure cookies, strict session handling, and short-lived service tokens.
- **Least-privilege runtime:** agent service account is read-only for clinical retrieval paths and cannot write clinical records.
- **Human send for patient-facing text:** the agent never contacts the patient or selects delivery channel; drafts are always physician-reviewed.

---

## 5. Data Retrieval Contract

Minimum retrieval domains **vary by use case**; all physician-visible responses include source linkage where the UI supports verification. In implementation, these domains map to the **seven schema-bound tools** in Section 3 (`list_schedule_slots` and **`get_calendar`** for schedule/list and calendar-window context; `get_patient_core_profile`, `get_medication_list`, `get_observations`, `get_encounters_and_notes`, and `get_referrals_orders_care_gaps` for chart content—the LLM selects which to invoke per request).

**Encounter-scoped (UC2, UC3, UC4, UC5):**
- Patient summary (demographics, active problems, allergies)
- Active medications (name, dose/frequency, **change vs prior visit** when computable)
- Recent labs (values, ranges, abnormal flags, dates)
- Recent encounter context (**today’s chief complaint / intake**, recent note excerpts)
- **UC2 / UC3:** care gaps, referral status (including **pending vs resulted**, long-pending without result)
- **UC4:** targeted slices (e.g. lab series, single med dose, imaging/referral timeline) per question
- **UC5:** **today’s** encounter note, assessment/plan, orders and referrals placed **this visit**, return instructions already in chart — **no** expansion into undocumented clinical opinion

**Schedule- and list-scoped (UC1, UC6, UC7):**
- Schedule rows (time, patient identifier as displayed, **new vs established** when on file, reason for visit / chief complaint / visit type from scheduling template, intake, or recent chart as available); **`get_calendar`** covers a bounded **date range** for calendar-style event and category metadata in the same read-only contract
- **Per-slot shallow chart facts:** e.g. final or new critical results on file, intake present vs missing when detectable, referral/imaging pending vs resulted, optional **UC3**-style hints when product policy includes them in scan mode
- **UC7:** same factual bar as briefing + flags for **missed** patients only; minimal line when nothing time-sensitive

---

## 6. Performance Targets

- **Early-morning day summary (UC1):** full typical day (~20 slots) in **under 20 seconds**
- **Pre-visit briefing (UC2):** within **5 seconds** of encounter open  
  (with pre-generation kicked off at intake completion where implemented)
- **Critical flag surfacing (UC3):** included in UC2 window; on-demand flag queries align with **UC4** budget when applicable
- **In-room Q&A (UC4):** answer within **8 seconds**
- **Post-appointment patient message draft (UC5):** draft ready within **10 seconds** of request
- **Post-lunch schedule summary (UC6):** **remaining** half-day in **under 15 seconds**
- **No-show sweep (UC7):** full typical missed list in **under 15 seconds**; optional per-patient expansion within **UC4**-class latency

If a dependency exceeds budget, return partial output with explicit gap disclosure.

---

## 7. PHI, Audit, and Safety

- PHI egress is controlled and minimized.
- Written compliance posture with **OpenRouter**, each **routed model provider** you enable, and **LangSmith** (and BAAs or equivalents where PHI-adjacent or PHI-containing workflows are involved) is required before production PHI use; treat gateways and trace hosts as part of the regulated data path.
- Traces store metadata (timing, tool path, verification status), not PHI values; disable or redact inputs/outputs in LangSmith where needed so payloads never contain identifiable clinical content.
- Audit log records request metadata and response integrity hash.
- Session context is isolated per physician-patient encounter; **day-wide** requests still log **per-slot** or **per-patient** access as required by policy.
- **UC5** drafts are PHI and remain under OpenEMR’s messaging workflow once the physician sends; the agent does not bypass that workflow.

---

## 8. Production Deployment and Operations

- **Environment model:** separate staging and production environments with config parity; production runs only with approved PHI controls.
- **Runtime topology:** OpenEMR app, MySQL, agent service, and edge proxy run in the private VPC behind HTTPS. LangSmith is a managed dependency outside the VPC boundary; network egress and trace content policies must reflect that split.
- **Reference Compose (dev / bring-up):** a Docker Compose stack matching the four in-VPC containers plus an optional Redis profile (`--profile redis`) is maintained at [`docker/clinical-copilot/`](docker/clinical-copilot/). It uses a self-signed edge certificate and pinned upstream images suitable for local integration—not a substitute for production TLS, secrets management, or AWS hardening.
- **Reliability:** health checks, restart policies, and rollback-ready versioned deploys are required for every release.
- **Data protection:** encryption in transit and at rest, routine backups, and tested restore procedures are mandatory.
- **Secrets management:** API keys and credentials are managed through a secrets manager and rotated on a defined schedule.
- **Operational visibility:** production alerts cover error rate, latency SLO drift (including **UC1**/**UC6**/**UC7** list latencies), tool failures, and authorization failures.

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

11. **UC5 draft inventing visit content or softening risk language**
    - **Audit signal:** model drift or weak verification can add undocumented claims or inappropriate reassurance.
    - **Mitigation:** strict retrieval to **documented** encounter artifacts only, post-generation claim audit against those artifacts, and explicit refusal paths for empty or contradictory notes.

12. **Schedule-wide leakage or over-breadth (UC1 / UC6 / UC7)**
    - **Audit signal:** a single request might aggregate more patients than the caller is authorized to see, or infer priorities from partial data.
    - **Mitigation:** per-slot authorization checks, fail-closed defaults, and verification rules that reject management or prioritization language not grounded in source fields.

---

## 10. Production Scope Limits

- No autonomous actions or order entry
- No longitudinal care-plan generation
- No inferred facts outside retrieved chart evidence
- **No clinical or operational recommendations** (including visit order, staffing, callbacks, or workup plans)
- Session memory is encounter-scoped and short-lived; day-wide views do not substitute for opening the chart for **UC2**-level depth
- No silent fallback to unchecked or uncited model output
- **Patient-facing text:** drafts only (**UC5**), always physician-edited and sent through standard OpenEMR messaging — not agent-sent

---

## 11. Preconditions for Real Clinical Use

1. Patient-scoping and authorization hardening are deployed and validated — including **schedule/list** paths for **UC1**, **UC6**, and **UC7**.
2. PHI governance controls are in place (including agreements covering OpenRouter, selected upstream models, and LangSmith trace handling).
3. Audit logging and encryption at rest are verified.
4. Adversarial and missing-data evals pass with no unsafe failures — including **UC5** “empty or contradictory note” behavior and **UC1**/**UC6** “no invented priorities” checks.
5. Physician review confirms outputs are accurate and useful in real workflow — **nurse intake → UC2** timing, **UC4** in-room latency, and **UC5** edit-and-send usability.
