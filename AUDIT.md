# OpenEMR System Audit

## Summary


OpenEMR's architecture is a mix of modern services/APIs and a large legacy codebase. This provides flexibility, but it also creates security, performance, and data-governance risks that require focused hardening.


### Security and Access Control
- Two high-priority authorization issues were identified: potentially incorrect route-skip matching and incomplete patient-level access checks in bearer-token flows. Together, these could allow broader-than-intended API access in some scenarios.
- Additional medium-risk concerns include permissive ACL behavior under certain misconfigurations and legacy session cookie defaults that increase impact if XSS or weak TLS configurations are present.
- The MFA/login flow still carries plaintext password state between steps, which enlarges credential exposure risk even if temporary.

### PHI Exposure and Logging
- Logging capabilities are strong, but current patterns can capture sensitive request/response payloads, SQL bind values, and audit comments containing PHI.
- Encryption support exists, but key material storage in database tables raises separation-of-duties and key-management concerns if the database is compromised.
- External/custom modules (fax, SMS, telehealth, connectors) increase potential PHI egress paths and require tighter centralized governance.

### Performance and Scalability
- Main bottlenecks are report-heavy legacy paths with N+1 query behavior, unbounded data pulls, expensive in-memory processing, and index-unfriendly query patterns.
- Hybrid request flow across modern and legacy layers adds overhead and complicates optimization.
- Some long-running tasks are asynchronous, but many expensive operations still run synchronously and can impact responsiveness.

### Architecture and Data Quality
- Core architecture is hybrid, with critical bridges between modern and legacy systems.
- Data quality controls are uneven: strong validation exists in some domains, but broad foreign-key enforcement is limited, increasing orphan/consistency risks.
- Heavy reliance on status flags and soft-delete patterns can produce stale or inconsistent reporting if filters are missed.

### Compliance and Regulatory Readiness
- Audit logging framework is robust, but coverage is configuration-dependent and therefore variable by deployment.
- No single, clearly enforced retention/disposal framework was found for PHI-bearing logs and related operational data.
- Breach-notification workflow support appears operationally underdefined in code.
- For LLM/AI use, centralized PHI egress policy and BAA-aligned controls are not yet platform-wide.


## Details 

### Security audit

#### Authentication and authorization risks

1. **High — route-skip authorization logic appears inverted and can over-match**
   - In `src/RestControllers/Authorization/SkipAuthorizationStrategy.php`, skip checks use `str_starts_with($route, $pathInfo)` instead of checking whether request path starts with configured skip route.
   - This increases risk of unexpected authorization bypass on routes sharing prefixes.

2. **High — patient context enforcement for bearer token flow is effectively stubbed**
   - In `src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php`, `checkUserHasAccessToPatient()` currently returns `true`.
   - SMART launch patient binding can therefore be accepted without robust provider-to-patient authorization proof.

3. **Medium — permissive ACL behavior on empty ACO specs**
   - `src/Common/Acl/AclMain.php` returns allow (`true`) in `aclCheckAcoSpec()` when spec is empty.
   - Misconfiguration or missing ACO metadata can silently fail open.

4. **Medium — core session cookie tradeoff increases XSS blast radius**
   - `src/Common/Session/SessionConfigurationBuilder.php` sets `cookie_httponly=false` for core sessions (`forCore()`), and default `cookie_secure=false`.
   - This is a legacy compatibility decision, but materially increases session theft risk if any XSS or non-TLS deployment path exists.

5. **Medium — plaintext password is forwarded during MFA challenge transitions**
   - Existing login/MFA flow (seen in `interface/main/main_screen.php`) carries `clearPass` between steps.
   - Even if later zeroed, this creates avoidable credential exposure surface in browser memory/DOM.

#### Data exposure vectors

1. **API request/response payload logging can capture PHI**
   - `src/RestControllers/Subscriber/ApiResponseLoggerListener.php` stores request URL and body/response content into audit structures when enabled.
   - Depending on endpoint usage, this can log sensitive data at scale.

2. **Audit comments and SQL binds may include sensitive values**
   - `src/Common/Logging/EventAuditLogger.php` appends bind values and SQL details into audit comments.
   - Good for traceability, but can over-collect sensitive payloads unless encryption and retention are tightly controlled.

3. **Large legacy + module surface increases exfiltration paths**
   - Custom modules under `interface/modules/custom_modules/` (fax/SMS/telehealth/connectors) create additional data egress vectors that depend on vendor-specific controls.

#### PHI handling observations

1. **Positive controls present**
   - Password hashing via `password_hash`/`password_verify` in `src/Common/Auth/AuthHash.php`.
   - Audit encryption support in `EventAuditLogger` (`enable_auditlog_encryption`).
   - Encrypted handling for some secrets (OAuth client secrets, MFA secrets) in auth/encryption services.

2. **Storage model raises key-management concerns**
   - Encryption key material in `sql` table `keys` (`sql/database.sql`) and legacy retrieval path in `src/Encryption/Storage/PlaintextKeyInDbKeysTableQueryUtils.php`.
   - Centralized but database-resident key storage can weaken separation-of-duties if DB compromise occurs.

#### HIPAA-relevant security gaps

1. **Need stronger least-privilege guarantees around patient-scoped API authorization.**
2. **Need explicit hardening defaults for secure cookies and strict TLS-only operations.**
3. **Need minimization controls for API/audit payload logging of PHI.**
4. **Need MFA-specific brute-force throttling evidence (not clearly visible in current flow).**

---

### Performance audit

### Where the system is slow / likely bottlenecks

1. **Report-heavy query patterns with N+1 behavior**
   - `interface/reports/collections_report.php` combines large aggregate SQL with per-row follow-up lookups.
   - `interface/reports/ippf_statistics.php` performs nested queries inside iteration loops.

2. **Unbounded and expression-heavy report queries**
   - Multiple reports pull large windows and perform expensive in-memory aggregation/sorting in PHP.
   - Expression-based sorts (example in `interface/reports/patient_list_creation.php`) can defeat indexing.

3. **Legacy procedural runtime overhead**
   - Request flow often traverses modern front controller then fallback into legacy scripts (`public/index.php` + `src/BC/FallbackRouter.php` + `interface/globals.php`), limiting optimizations and adding initialization cost.

4. **Authorization/session overhead for API**
   - Per-request token verification, scope validation, session setup, and optional response logging add latency in high-throughput API paths.

### Data structure and performance constraints

1. **Large schema with mixed indexing quality**
   - `sql/database.sql` has many indexes, but some operational tables (for example `api_log`) have limited secondary indexing for analytics/access patterns.

2. **Minimal DB-enforced referential integrity**
   - Base schema generally lacks explicit foreign key constraints, pushing consistency and join correctness into application logic.

3. **Background execution framework exists, but not universal**
   - Background services (`src/Services/Background/*`) help isolate long-running work, yet many expensive report/data operations still run synchronously in request cycle.

### Constraints affecting agent response latency

1. **Synchronous report endpoints can exceed practical LLM interaction budgets.**
2. **FHIR bulk export is asynchronous/poll-based by design and time-window bounded; agent workflows must support deferred completion.**
3. **Session locking and legacy global context can serialize parts of request handling under contention.**
4. **Crossing legacy and modern layers complicates quick instrumentation and optimization rollouts.**

---

### Architecture audit

### System organization

1. **Hybrid architecture**
   - Modern namespaced code in `src/` with Symfony/Laminas components.
   - Significant legacy procedural/controllers in `library/` and `interface/`.

2. **Primary runtime flow**
   - Web entrypoint through `public/index.php`.
   - Routing bridge via `src/BC/FallbackRouter.php`.
   - Global bootstrapping via `interface/globals.php`.
   - API flow through `apis/dispatch.php` + `src/RestControllers/ApiApplication.php`.

3. **Module ecosystem**
   - Laminas modules and custom modules (`interface/modules/zend_modules/`, `interface/modules/custom_modules/`) are first-class extensibility mechanisms.

### Where data lives

1. **Primary relational data in MySQL schema**
   - Base schema: `sql/database.sql`.
   - Legacy upgrades: `sql/*_upgrade.sql`.
   - Newer migration scaffolding present in `db/` (Doctrine migration config), but migration model appears split.

2. **Audit/operational data**
   - `log`, `extended_log`, `api_log`, `log_comment_encrypt`, `session_tracker`, and background-service tables in core schema.

### Layer interactions

1. **Legacy-to-modern bridge is active and critical**
   - Modern services/controllers often interact with legacy DB and global/session state.

2. **API stack uses event subscribers**
   - Authorization, CORS, response logging, and session cleanup are subscriber-driven.

3. **ACL/RBAC relies on phpGACL-era structures**
   - Mature and broad, but configuration complexity is high and fail-open edge cases exist.

### Integration points for new capabilities

1. **REST route extension events and custom modules** are the safest primary extension seam.
2. **Background services framework** is suitable for long-running agent tasks (batch summarization, reconciliation, async coding suggestions).
3. **FHIR/SMART endpoints** provide standards-aligned integration for patient/clinical workflows.
4. **Risk:** adding capabilities directly in legacy scripts increases coupling and maintenance burden.

---

### Data Quality audit

### Completeness and consistency

1. **Validation maturity is uneven**
   - Strong validators exist for key domains (`PatientValidator`, `EncounterValidator`, `CoverageValidator`).
   - Some domains are under-constrained (for example lightweight validators with minimal rules).

2. **No broad FK enforcement in schema**
   - Increases orphaned records and cross-table mismatch risks, especially under module/custom-script writes.

3. **Soft-delete/status-flag model is pervasive**
   - Many tables rely on `active`, `deleted`, and state flags rather than hard constraints.
   - Queries that omit these filters can produce inconsistent or stale results.

### Duplicate records

1. **Duplicate-prevention logic is partly app-layer only**
   - Example: coverage duplicate checks in validator logic.
   - Without stronger unique constraints in all relevant tables, race conditions and import edge cases can still create duplicates.

2. **Heuristic linking pathways can mis-associate**
   - Person/patient linking and reconciliation logic may produce false positives/negatives in edge cases.

### Stale data and agent failure modes

1. **Report paths can mutate operational state**
   - Example patterns where reporting flow updates status flags (e.g., collection-related flags) increase drift risk.

2. **Derived business-state heuristics in reports**
   - Human-interpreted state inference can diverge from transactional truth and mislead downstream agent reasoning.

3. **Key agent risk**
   - Any LLM feature relying on loosely filtered joins or stale flags can generate clinically or administratively incorrect recommendations.

---

### Compliance & Regulatory audit

### Audit logging requirements (HIPAA Security Rule)

1. **Strengths**
   - Dedicated audit framework in `src/Common/Logging/EventAuditLogger.php`.
   - Event categorization, optional encryption, checksum/tamper support structures, and optional ATNA sink support.
   - API activity logging path present (`ApiResponseLoggerListener`).

2. **Gaps**
   - Logging coverage is configurable and can be partially disabled (`AuditConfig`), creating deployment-dependent compliance variability.
   - High-risk events may not be uniformly captured if modules/legacy paths bypass common logging hooks.
   - Logging of full API payloads may conflict with data-minimization principles unless tightly governed.

### Data retention policy and disposal

1. **Finding**
   - Core schema and code show log/session storage but no single, explicit repository-level retention/deletion policy governing PHI-bearing logs (`log`, `api_log`, `extended_log`, `log_comment_encrypt`) and associated purge controls.

2. **Compliance impact**
   - Organizations need documented retention schedules, legal-hold behavior, and secure disposal procedures to satisfy HIPAA and state-law overlays.

### Breach notification obligations

1. **Finding**
   - No obvious code-level workflow for breach triage, notification timelines, and evidence package generation.
   - Some security/audit data exists to support investigations, but incident response process artifacts are not clearly encoded in-system.

2. **Compliance impact**
   - Covered entities/business associates still need operational runbooks to meet HIPAA breach notification timing and content obligations.

### BAA implications for LLM provider use

1. **Finding**
   - Repository includes external communication modules (SMS/fax/telehealth connectors), but no centralized LLM-specific PHI egress governance or provider policy layer is evident.
   - A few module-level docs mention BAA concerns, but this is not a platform-wide enforcement mechanism.

2. **Compliance impact**
   - Sending PHI to any LLM provider requires:
     - executed BAA with provider and relevant subprocessors,
     - data-use restrictions (no model training on PHI),
     - regional/data residency review,
     - retention/deletion guarantees,
     - auditable consent and minimum-necessary controls.
   - Without this governance, LLM integrations can create immediate HIPAA exposure.

---

### Prioritized remediation roadmap

1. **Immediate (0-30 days)**
   - Fix `SkipAuthorizationStrategy` route matching logic.
   - Implement real patient-access validation in bearer-token patient context checks.
   - Enforce secure session defaults (`Secure`, `HttpOnly` where feasible) and document required deployment hardening.
   - Turn on and verify audit encryption + validate API log minimization settings.

2. **Near-term (30-90 days)**
   - Build/standardize retention and purge framework for audit/API logs and sensitive operational tables.
   - Add MFA verification throttling/lockout telemetry.
   - Add targeted indexes and query rewrites for worst report bottlenecks.
   - Define “agent-safe data contract” for complete, current, and authoritative fields.

3. **Mid-term (90+ days)**
   - Reduce fail-open authorization/config defaults.
   - Expand DB-level integrity constraints where safe.
   - Consolidate migration strategy (legacy SQL + Doctrine) to reduce schema drift.
   - Introduce centralized PHI egress governance for all external AI/communications integrations.

### Audit confidence and limitations

- Confidence: **medium-high** for architectural, code-path, and control-surface findings.
- Limitations: no runtime load tests, no production configuration inspection, no live exploit testing, no legal determination.
- Recommendation: pair this static audit with staged penetration testing, log-review sampling, and legal/compliance counsel validation before production AI deployment.
