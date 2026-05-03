"""Ensure ``app`` is importable when pytest is run from any working directory."""

from __future__ import annotations

import os
import sys
from pathlib import Path

# Unit tests must not depend on a live OpenEMR HTTP tier for retrieval tools.
os.environ.setdefault("COPILOT_USE_OPENEMR_RETRIEVAL", "false")

_AGENT_ROOT = Path(__file__).resolve().parent.parent
if str(_AGENT_ROOT) not in sys.path:
    sys.path.insert(0, str(_AGENT_ROOT))
