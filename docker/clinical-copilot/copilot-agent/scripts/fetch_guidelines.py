"""Fetch clinical guideline plain text from public sources listed in sources.json.

Run at container build time (or manually) to populate app/guidelines/*.txt.
The .txt files are .gitignored — only sources.json is checked in.

Usage:
    python scripts/fetch_guidelines.py
    python scripts/fetch_guidelines.py --sources app/guidelines/sources.json --out app/guidelines
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.request
import urllib.error
from html.parser import HTMLParser
from pathlib import Path


class _TextExtractor(HTMLParser):
    """Strip HTML tags and collect visible text."""

    _SKIP_TAGS = frozenset({"script", "style", "nav", "footer", "header", "noscript"})

    def __init__(self) -> None:
        super().__init__()
        self._skip_depth = 0
        self._parts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list) -> None:
        if tag.lower() in self._SKIP_TAGS:
            self._skip_depth += 1

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() in self._SKIP_TAGS:
            self._skip_depth = max(0, self._skip_depth - 1)

    def handle_data(self, data: str) -> None:
        if self._skip_depth == 0:
            stripped = data.strip()
            if stripped:
                self._parts.append(stripped)

    def get_text(self) -> str:
        raw = " ".join(self._parts)
        # Collapse runs of whitespace to single space, then normalise newlines.
        return re.sub(r"[ \t]{2,}", " ", raw).strip()


def _fetch_text(url: str, timeout: int = 30) -> str:
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (compatible; OpenEMR-ClinicalCopilot/1.0; "
            "+https://www.open-emr.org/)"
        ),
        "Accept": "text/html,application/xhtml+xml,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9",
    }
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        charset = "utf-8"
        content_type = resp.headers.get("Content-Type", "")
        if "charset=" in content_type:
            charset = content_type.split("charset=")[-1].strip().split(";")[0].strip()
        raw_bytes: bytes = resp.read()

    # Try declared charset, fall back to utf-8 with replacement.
    try:
        raw_text = raw_bytes.decode(charset)
    except (UnicodeDecodeError, LookupError):
        raw_text = raw_bytes.decode("utf-8", errors="replace")

    extractor = _TextExtractor()
    extractor.feed(raw_text)
    return extractor.get_text()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Fetch clinical guidelines as plain text.")
    parser.add_argument(
        "--sources",
        default="app/guidelines/sources.json",
        help="Path to sources.json manifest.",
    )
    parser.add_argument(
        "--out",
        default="app/guidelines",
        help="Output directory for .txt files.",
    )
    parser.add_argument(
        "--delay",
        type=float,
        default=1.5,
        help="Seconds to wait between requests (be polite).",
    )
    parser.add_argument(
        "--fail-on-errors",
        action="store_true",
        help="Exit non-zero if any source fails (default is warning-only).",
    )
    args = parser.parse_args(argv)

    sources_path = Path(args.sources)
    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)

    if not sources_path.exists():
        print(f"ERROR: sources file not found: {sources_path}", file=sys.stderr)
        return 1

    sources: list[dict] = json.loads(sources_path.read_text(encoding="utf-8"))
    ok = 0
    failed = 0

    for entry in sources:
        url: str = entry["url"]
        filename: str = entry["filename"]
        description: str = entry.get("description", url)
        out_path = out_dir / filename

        print(f"Fetching: {description}")
        print(f"  URL: {url}")
        try:
            text = _fetch_text(url)
            if len(text) < 100:
                print(f"  WARNING: very short content ({len(text)} chars) — skipping")
                failed += 1
            else:
                out_path.write_text(text, encoding="utf-8")
                print(f"  Saved {len(text):,} chars → {out_path}")
                ok += 1
        except urllib.error.HTTPError as exc:
            print(f"  ERROR: HTTP {exc.code} {exc.reason}", file=sys.stderr)
            failed += 1
        except urllib.error.URLError as exc:
            print(f"  ERROR: {exc.reason}", file=sys.stderr)
            failed += 1
        except Exception as exc:  # noqa: BLE001
            print(f"  ERROR: {exc}", file=sys.stderr)
            failed += 1

        if args.delay > 0:
            time.sleep(args.delay)

    print(f"\nDone — {ok} fetched, {failed} failed.")
    if failed > 0:
        print("WARNING: one or more guideline sources failed; continuing with partial corpus.", file=sys.stderr)
    return 1 if (args.fail_on_errors and failed > 0) else 0


if __name__ == "__main__":
    sys.exit(main())
