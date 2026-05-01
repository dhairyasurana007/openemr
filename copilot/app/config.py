"""Runtime configuration for the copilot service."""

from pathlib import Path

# Repository layout: copilot/app/config.py -> copilot/
COPILOT_ROOT = Path(__file__).resolve().parent.parent
DATA_DIR = Path(__file__).resolve().parent.parent / "data"

# Hardcoded administrator (HTTP Basic). Demo / local dev only — not for production PHI.
ADMIN_USERNAME = "admin"
ADMIN_PASSWORD = "pass"
