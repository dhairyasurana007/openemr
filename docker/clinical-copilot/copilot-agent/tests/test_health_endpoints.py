"""Smoke tests for health routes (run from copilot-agent dir: PYTHONPATH=. python -m unittest)."""

from __future__ import annotations

import os
import unittest

os.environ.setdefault("COPILOT_READYZ_PROBE_OPENEMR", "false")
os.environ.setdefault("COPILOT_MAX_INFLIGHT", "0")

from fastapi.testclient import TestClient

import app.main as main


class TestHealthEndpoints(unittest.TestCase):
    def test_livez(self) -> None:
        with TestClient(main.app) as client:
            response = client.get("/meta/health/livez")
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.json()["status"], "alive")

    def test_readyz_process_only(self) -> None:
        with TestClient(main.app) as client:
            response = client.get("/meta/health/readyz")
            self.assertEqual(response.status_code, 200)
            body = response.json()
            self.assertEqual(body["status"], "ready")
            self.assertTrue(body["checks"]["process"])

    def test_legacy_health(self) -> None:
        with TestClient(main.app) as client:
            response = client.get("/health")
            self.assertEqual(response.status_code, 200)
            self.assertEqual(response.json()["status"], "ok")
