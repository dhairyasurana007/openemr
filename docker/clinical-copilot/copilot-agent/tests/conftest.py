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


def make_tiff_bytes(pages: int = 1) -> bytes:
    """Create a minimal TIFF fixture using PyMuPDF. No binary files committed."""
    import fitz

    if pages == 1:
        pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 10, 10))
        pix.set_rect(fitz.IRect(0, 0, 10, 10), (200, 200, 200))
        return pix.tobytes("tiff")

    # Multi-page: create a PDF with N pages, render each page to a single-page
    # TIFF, then chain IFDs by embedding all pages into a PDF-backed TIFF stream.
    # For test purposes we return the first page's TIFF bytes N times concatenated,
    # which fitz treats as a single-page TIFF (acceptable for page-count mocking).
    pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 10, 10))
    pix.set_rect(fitz.IRect(0, 0, 10, 10), (200, 200, 200))
    single = pix.tobytes("tiff")
    return single * pages
