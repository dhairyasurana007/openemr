[![Syntax Status](https://github.com/openemr/openemr/actions/workflows/syntax.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/syntax.yml)
[![Styling Status](https://github.com/openemr/openemr/actions/workflows/styling.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/styling.yml)
[![Testing Status](https://github.com/openemr/openemr/actions/workflows/reusable-openemr-docker-tests.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/reusable-openemr-docker-tests.yml)
[![JS Unit Testing Status](https://github.com/openemr/openemr/actions/workflows/js-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/js-test.yml)
[![PHPStan](https://github.com/openemr/openemr/actions/workflows/phpstan.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/phpstan.yml)
[![Rector](https://github.com/openemr/openemr/actions/workflows/rector.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/rector.yml)
[![ShellCheck](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml)
[![Docker Compose Linting](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml)
[![Dockerfile Linting](https://github.com/openemr/openemr/actions/workflows/hadolint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/hadolint.yml)
[![Isolated Tests](https://github.com/openemr/openemr/actions/workflows/ci-isolated-phpunit-tests.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/ci-isolated-phpunit-tests.yml)
[![Inferno Certification Test](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml)
[![Composer Checks](https://github.com/openemr/openemr/actions/workflows/composer.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer.yml)
[![Composer Require Checker](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml)
[![API Docs Freshness Checks](https://github.com/openemr/openemr/actions/workflows/api-docs.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/api-docs.yml)
[![codecov](https://codecov.io/gh/openemr/openemr/graph/badge.svg?token=7Eu3U1Ozdq)](https://codecov.io/gh/openemr/openemr)

[![Backers on Open Collective](https://opencollective.com/openemr/backers/badge.svg)](#backers) [![Sponsors on Open Collective](https://opencollective.com/openemr/sponsors/badge.svg)](#sponsors)

# OpenEMR


[OpenEMR](https://open-emr.org) is a Free and Open Source electronic health records and medical practice management application. It features fully integrated electronic health records, practice management, scheduling, electronic billing, internationalization, free support, a vibrant community, and a whole lot more. It runs on Windows, Linux, Mac OS X, and many other platforms.

### Contributing

OpenEMR is a leader in healthcare open source software and comprises a large and diverse community of software developers, medical providers and educators with a very healthy mix of both volunteers and professionals. [Join us and learn how to start contributing today!](https://open-emr.org/wiki/index.php/FAQ#How_do_I_begin_to_volunteer_for_the_OpenEMR_project.3F)

> Already comfortable with git? Check out [CONTRIBUTING.md](CONTRIBUTING.md) for quick setup instructions and requirements for contributing to OpenEMR by resolving a bug or adding an awesome feature 😊.

### Support

Community and Professional support can be found [here](https://open-emr.org/wiki/index.php/OpenEMR_Support_Guide).

Extensive documentation and forums can be found on the [OpenEMR website](https://open-emr.org) that can help you to become more familiar about the project 📖.

### Reporting Issues and Bugs

Report these on the [Issue Tracker](https://github.com/openemr/openemr/issues). If you are unsure if it is an issue/bug, then always feel free to use the [Forum](https://community.open-emr.org/) and [Chat](https://www.open-emr.org/chat/) to discuss about the issue 🪲.

### Reporting Security Vulnerabilities

Check out [SECURITY.md](.github/SECURITY.md)

### API

Check out [API_README.md](API_README.md)

### Docker

Check out [DOCKER_README.md](DOCKER_README.md)

### FHIR

Check out [FHIR_README.md](FHIR_README.md)

### For Developers

If using OpenEMR directly from the code repository, then the following commands will build OpenEMR (Node.js version 24.* is required) :

```shell
composer install --no-dev
npm install
npm run build
composer dump-autoload -o
```

### Contributors

This project exists thanks to all the people who have contributed. [[Contribute]](CONTRIBUTING.md).
<a href="https://github.com/openemr/openemr/graphs/contributors"><img src="https://opencollective.com/openemr/contributors.svg?width=890" /></a>


### Sponsors

Thanks to our [ONC Certification Major Sponsors](https://www.open-emr.org/wiki/index.php/OpenEMR_Certification_Stage_III_Meaningful_Use#Major_sponsors)!


### License

[GNU GPL](LICENSE)

---

## Week 2 — Clinical Co-Pilot Multimodal Flow

The `docker/clinical-copilot/copilot-agent/` directory contains a FastAPI +
LangGraph agent that adds AI-assisted document extraction, hybrid RAG retrieval,
and FHIR round-trip persistence to OpenEMR.

### Required Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `OPENROUTER_API_KEY` | Yes | Routes chat completions and VLM document extraction |
| `VLM_MODEL` | No | Model for document extraction (default: `anthropic/claude-sonnet-4.6`) |
| `OPENROUTER_MODEL` | No | Model for supervisor / answer composer (default: `anthropic/claude-3.5-haiku`) |
| `OPENEMR_INTERNAL_HOSTPORT` | Yes | Host:port of the OpenEMR web container (e.g. `openemr-web:80`) |
| `CLINICAL_COPILOT_INTERNAL_SECRET` | Yes (prod) | Shared secret for `X-Clinical-Copilot-Internal-Secret` header |
| `COHERE_API_KEY` | No | Enables Cohere `rerank-english-v3.0` in the RAG pipeline |
| `LANGCHAIN_API_KEY` | No | LangSmith tracing (auto-enables when key is present) |
| `LANGCHAIN_TRACING_V2` | No | Override tracing on/off (default: on when key set) |
| `LANGCHAIN_PROJECT` | No | LangSmith project name (default: `clinical-copilot`) |
| `GUIDELINES_CORPUS_DIR` | No | Directory of plain-text guideline files (default: `app/guidelines`) |

### Running the Week 2 Flow End-to-End

```bash
# 1. Start OpenEMR (includes the copilot-agent sidecar)
cd docker/development-easy && docker compose up --detach --wait

# 2. Run the copilot-agent unit + integration tests
cd ../clinical-copilot/copilot-agent && python -m pytest tests -v

# 3. Run the 50-case offline eval suite (must exit 0 for CI to pass)
python evals/run_evals.py
```

### Week 1 vs Week 2

| Area | Week 1 | Week 2 |
|------|--------|--------|
| Document formats | PDF, PNG, JPEG | + TIFF, DOCX, XLSX, HL7v2 |
| Doc-type selection | Manual dropdown | Auto-detected (heuristic → VLM) |
| FHIR persistence | None | `DocumentReference` + `Observation` round-trip |
| Retrieval | Chart tools only | Chart tools + hybrid RAG (BM25 + dense + Cohere) |
| Observability | Basic logs | Per-encounter `EncounterTrace` + PHI redaction |
| Eval | Manual smoke tests | 50-case golden suite with CI gate |
| Citation UI | Text only | Clickable bbox overlay on rendered PDF |

### Design Details

See [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md) for full design documentation
covering the ingestion flow, worker graph, RAG pipeline, eval gate, FHIR
sequence, and observability schema.

See [W2_COST_LATENCY_REPORT.md](W2_COST_LATENCY_REPORT.md) for cost/latency
measurements, projected production costs, and bottleneck analysis.
