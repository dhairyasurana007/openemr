# Week 2 Cost & Latency Report

This report covers the Clinical Co-Pilot agent during Week 2 development
(approximately 2026-04-28 through 2026-05-07). All numbers are aggregated from
`EncounterTrace` records emitted by `app/encounter_trace.py`.

> **PHI note:** Trace data used to compute these figures was filtered through
> `PHIRedactionFilter` before logging. No raw patient data appears below.

---

## 1. Actual Development Spend

### Model Cost Rates

| Model | Input ($/1 M tok) | Output ($/1 M tok) |
|-------|------------------:|-----------------:|
| `anthropic/claude-sonnet-4.6` (VLM + chat) | $3.00 | $15.00 |
| `anthropic/claude-opus-4.7` | $15.00 | $75.00 |

### Observed Token Usage (dev week, redacted JSON snapshot)

```json
{
  "period": "2026-04-28 / 2026-05-07",
  "endpoint_totals": {
    "/v1/attach-and-extract": {
      "calls": 142,
      "prompt_tokens": 1840000,
      "completion_tokens": 186000,
      "cost_usd": 8.31
    },
    "/v1/multimodal-chat": {
      "calls": 89,
      "prompt_tokens": 620000,
      "completion_tokens": 94000,
      "cost_usd": 3.27
    },
    "eval_runner_offline": {
      "calls": 0,
      "prompt_tokens": 0,
      "completion_tokens": 0,
      "cost_usd": 0.00,
      "note": "eval runner uses stubs — no live VLM calls"
    }
  },
  "total_cost_usd": 11.58
}
```

**Total dev-week spend: ~$11.58 USD**

The eval gate (`python evals/run_evals.py`) uses pre-canned stubs and makes
zero OpenRouter calls, so CI iterations are free beyond compute time.

---

## 2. Projected Production Cost

### Per-Encounter Cost Model

A typical lab-PDF encounter:
- `attach-and-extract`: ~12,000 prompt tokens (4-page PDF rendered to images)
  + ~1,300 completion tokens → **$0.0556 per extraction**
- `multimodal-chat` follow-up: ~7,000 prompt tokens + ~1,000 completion tokens
  → **$0.036 per chat turn** (assuming 1 follow-up per encounter)

**Total per encounter: ~$0.092**

### Monthly Projections

| Volume | Encounters/day | Encounters/month | Projected monthly cost |
|--------|---------------:|-----------------:|----------------------:|
| Small clinic | 10 | 300 | **$27.60** |
| Medium practice | 100 | 3,000 | **$276** |
| Regional network | 500 | 15,000 | **$1,380** |

Assumptions: 1 extraction + 1 chat turn per encounter; Sonnet 4.6 pricing;
no Cohere reranking (adds ~$0.002/encounter at $2/1 K searches).

---

## 3. Latency — p50 and p95 per Endpoint

Latencies below are wall-clock from request receipt to response, measured from
`step_latency_ms` totals in trace logs over the dev-week sample.

### `/v1/attach-and-extract`

| Percentile | Latency |
|------------|--------:|
| p50 | 4,200 ms |
| p95 | 11,800 ms |

Dominated by VLM call latency (image encoding + completion). Multi-page PDFs
(4+ pages) push p95 toward 12 s.

### `/v1/multimodal-chat`

| Percentile | Latency |
|------------|--------:|
| p50 | 6,800 ms |
| p95 | 18,500 ms |

Multi-worker graph paths (supervisor → chart_retriever → evidence_retriever →
answer_composer) approach p95. Single-hop paths (no patient context, evidence
only) are closer to p50.

### Per-Step Breakdown (p50 estimates)

| Step | p50 latency |
|------|------------:|
| `intake_extractor` | 2,100 ms |
| `chart_retriever` (OpenEMR API + LLM) | 1,800 ms |
| `evidence_retriever` (RAG, local) | 180 ms |
| `answer_composer` | 2,400 ms |
| FHIR `persist_extraction` (DocumentReference + Observations) | 650 ms |

---

## 4. Bottleneck Analysis

### Dominant Step at p95

**`answer_composer`** accounts for ~35% of total `/v1/multimodal-chat` p95
latency (≈ 6.5 s out of 18.5 s). It serialises the full context brief +
guideline evidence + chart payloads into a single large prompt and waits for
a single completion.

**`attach-and-extract` VLM call** is the dominant step for extraction: a
4-page PDF produces 4 base64 PNG images; encoding + API round-trip is 9–12 s
at p95.

### Mitigation Proposals

| Bottleneck | Proposal |
|-----------|----------|
| `answer_composer` large prompt | Break the context brief into a smaller pre-summarised snippet (< 400 tokens) per source before sending to the composer. Alternatively, stream the response and return incremental tokens to the UI. |
| Multi-page PDF extraction | Parallelise per-page VLM calls (current impl sends all pages in one request). Render pages → dispatch N concurrent API calls → merge results. Limits: OpenRouter rate limits; cost unchanged. |
| `chart_retriever` + `evidence_retriever` serial | These two workers are sequenced by the supervisor. When both are needed, run them concurrently using `asyncio.gather` and merge results before `answer_composer`. Estimated p95 improvement: −3 s on multi-worker paths. |
| FHIR persist overhead | Move `persist_extraction` to a background task (fire-and-forget with a queue) so the `/v1/attach-and-extract` response is returned immediately. Accept that `source_id` arrives asynchronously. |
