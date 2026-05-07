FROM python:3.12-slim-bookworm

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY schemas ./schemas
COPY scripts ./scripts

EXPOSE 8000
# Render sets PORT on web services; local Compose uses 8000.
CMD ["sh", "-c", "if [ \"${FETCH_GUIDELINES_ON_START:-true}\" = \"true\" ]; then python scripts/fetch_guidelines.py --sources app/guidelines/sources.json --out app/guidelines || echo \"guideline_fetch_failed\"; fi; exec uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-8000}"]
