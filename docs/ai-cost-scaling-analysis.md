# AI Cost & Architecture Scaling Analysis

Brief model for **development spend**, **production inference/hosting**, and **architecture** at **100 / 1K / 10K / 100K** monthly active users (MAU). Figures are **order-of-magnitude planning anchors**, not quotes—reconcile Render SKUs, OpenRouter (or other LLM) usage, and your dashboards; build a small spreadsheet keyed to **tokens per user journey** and refresh periodically.

---

## Why this is not `cost_per_token × users`

| Factor | Effect on spend |
|--------|------------------|
| **Usage distribution** | Power users dominate token spend; median user ≠ mean. |
| **Prompt & context size** | RAG, chat history, and tool outputs multiply input tokens per session. |
| **Caching** | Repeated system prompts, static context, and duplicate queries amortize cost. |
| **Batch vs real-time** | Async summarization, offline jobs, and pre-computation shift spend off the hot path. |
| **Model routing** | Small/fast models for classification + large models only when needed changes $/request nonlinearly. |
| **Concurrency & peaks** | Billing is driven by peak QPS and queue depth, not average daily users. |
| **Vendor tiers & commits** | Reserved capacity, enterprise discounts, and self-hosting alter marginal cost per user. |
| **Failure & retries** | Timeouts, duplicate submissions, and evaluation pipelines add “hidden” tokens. |

Production cost scales with **tokens per meaningful outcome**, **SLO-driven redundancy**, and **operational surface area**—not headcount alone.

---

## Development spend (typical buckets)

Largely **fixed or stair-stepped** before production traffic dominates the bill.

| Category | What it buys | Notes |
|----------|----------------|--------|
| **Discovery & safety** | Use cases, red-teaming, PHI/PII handling, audit trails | Often 15–30% of early AI program time |
| **Integration** | APIs, auth, logging, idempotency, streaming UX | Dominates “first real feature” calendar time |
| **Evaluation** | Datasets, regression tests, human review, quality gates | Recurring; grows with each model/version |
| **MLOps / observability** | Tracing, cost dashboards, sampling, alerting | Cheap to defer; expensive to retrofit |
| **Prompt & tool engineering** | Templates, JSON modes, function calling, fallbacks | Ongoing; not one-time |

**Rule of thumb:** Until you have meaningful production traffic, **dev + eval + compliance** often exceeds **raw API inference**. After scale, **inference + fine-tuning + storage** can flip that balance.

---

## Approximate monthly production cost

Assume **MAU** with similar **sessions per user** to a small cohort unless you know otherwise. The **~25 MAU** row is the measured-style anchor (~$20 Postgres 4 GB, ~$40 light LLM API); higher tiers assume **larger DB, caching, workers, routing, and token budgets**—so OpenRouter is **not** scaled as `($40 / 25) × MAU`. Skip those controls and API cost can be **several times** the API column.

### Summary (~USD / month)

| MAU | Render Postgres (est.) | OpenRouter / LLM API (est.) | Other Render + data (est.) | **~Total** | Dev / program emphasis |
|-----|--------------------------|-----------------------------|----------------------------|------------|-------------------------|
| **~25** (anchor) | $20 (4 GB) | $40 | $0 | **~$60** | Product + integration |
| **100** | $65 (8 GB) | $140 | $25 | **~$230** | Compliance + limits |
| **1,000** | $230 (16 GB + read replica) | $550 | $120 | **~$900** | Eval + routing |
| **10,000** | $720 (32 GB class + HA replica) | $2,200 | $580 | **~$3,500** | Reliability + cost guardrails |
| **100,000** | $3,200 (dedicated / cluster-style) | $18,000 | $4,800 | **~$26,000** | Platform + vendor strategy |

**Postgres column:** Instance size, read replica, and HA as connections, writes, and jobs grow. **Other:** Redis/cache, extra web/worker services, object storage, vector/RAG, log retention—staged per tier below. **API column:** Sublinear vs naive user scaling because of the architecture in each tier; watch **oversized contexts** (cost faster than MAU), **tool/agent loops** (cap steps per task), and **latency vs autoscaling** (may force always-on capacity).

---

## Architecture by scale (with cost implications)

### ~25 users — baseline

Same as the anchor row above: single **4 GB** Postgres, **direct** LLM calls from the app, minimal background inference, one small web service, and **provider spend alerts** (no replica, no Redis yet).

### ~100 MAU — **~$230 / month**

| Change | Why | ~Cost impact |
|--------|-----|----------------|
| **Postgres → 8 GB** | Headroom for rows, indexes, connections | ~$20 → **~$65** |
| **Redis** (cache + rate limits) | Session caps, prompt/idempotency cache, throttle abuse | **~+$25** (in “other”) |
| **OpenRouter ~$140** | ~4× anchor users with early **prompt caching** and **per-user limits** | **~$140** |
| **Logging + retention** | Short window on Render or bucket | folded into **other** or negligible |

Single primary app; optional worker for email/jobs.

### ~1,000 MAU — **~$900 / month**

| Change | Why | ~Cost impact |
|--------|-----|----------------|
| **Postgres 16 GB + read replica** | Read scaling; safer failover | **~$230** combined |
| **Dedicated worker** | LLM off request thread; retries without blocking | **~+$45** (in “other”) |
| **Model router** | Cheap vs expensive models; cuts average $/completion | Holds API **~$550** vs naive thousands |
| **Larger Redis** | More cache keys, rate-limit cardinality | part of ops |
| **Vector / RAG** (small tier or pgvector + disk) | Embeddings + query | **~+$120** “other” |
| **Staging** (smaller) | Safe deploys | folded into **other** |

### ~10,000 MAU — **~$3,500 / month**

| Change | Why | ~Cost impact |
|--------|-----|----------------|
| **Postgres ~32 GB + HA replica** | Sustained writes, analytics, pools | **~$720** |
| **Multiple workers + autoscaling floor** | Peak queues, scheduled summarization | **~+$200** “other” |
| **Strong RAG** (dedicated vector or scaled pgvector) | Tighter retrieval lowers tokens vs “dump whole chart” | **~+$250** “other”; lowers API |
| **OpenRouter ~$2,200** | Volume with **router + cache + async** (not naive 400× anchor) | **~$2,200** |
| **Object storage + log retention** | Audits, exports | **~+$130** “other” |

### ~100,000 MAU — **~$26,000 / month**

| Change | Why | ~Cost impact |
|--------|-----|----------------|
| **Database cluster / dedicated** | HA, backups, possible sharding or split read/write | **~$3,200** |
| **Multi-region + CDN + extra Redis** | Latency, resilience | **~+$1,200** “other” |
| **Inference strategy** | Enterprise API, volume discounts, **partial self-host** for high-volume low-variance tasks | **~$18,000** API (wide band **$12k–$30k+** if chat/context-heavy) |
| **Observability + security** | Tracing, heavier logging, abuse detection | **~+$400** “other” |
| **Large vector + search** | RAG at scale | **~+$800** “other” |
| **FinOps + quotas** | Per-tenant caps, prepaid credits | Runway control; engineering cost to build |

At this scale **people** (SRE, support, compliance) often exceeds **~$26k** infra + API—model headcount separately.

---

## Assumptions to document in your own model

1. **Definition of “user”** (MAU vs WAU vs seats vs API consumers).
2. **Sessions and turns** per user per month (P50 / P95).
3. **Average and P95** prompt + completion tokens per turn.
4. **Share of traffic** eligible for cache, batch, or smaller models.
5. **Uptime target** and acceptable **degraded mode** (drives redundancy cost).
6. **Data residency** and **retention** (drives storage and replication cost).
