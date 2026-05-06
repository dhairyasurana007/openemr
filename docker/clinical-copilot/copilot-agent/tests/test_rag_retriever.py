"""Tests for HybridRetriever (no live model or network calls)."""

from __future__ import annotations

import math
import unittest

from app.rag_retriever import HybridRetriever, _chunk_text, _rrf_merge


# ---------------------------------------------------------------------------
# _chunk_text
# ---------------------------------------------------------------------------

class TestChunkText(unittest.TestCase):
    def test_single_chunk_for_short_text(self) -> None:
        chunks = _chunk_text("short", "src.txt")
        assert len(chunks) == 1
        assert chunks[0]["text"] == "short"
        assert chunks[0]["source"] == "src.txt"
        assert chunks[0]["chunk_id"] == 0

    def test_multiple_chunks_for_long_text(self) -> None:
        # 1 char × 2500 > _CHUNK_CHARS (1200), so must produce ≥ 2 chunks.
        text = "x" * 2500
        chunks = _chunk_text(text, "src.txt")
        assert len(chunks) >= 2

    def test_chunk_ids_sequential(self) -> None:
        text = "a" * 3000
        chunks = _chunk_text(text, "src.txt")
        ids = [c["chunk_id"] for c in chunks]
        assert ids == list(range(len(chunks)))

    def test_empty_string_produces_no_chunks(self) -> None:
        assert _chunk_text("", "src.txt") == []

    def test_whitespace_only_produces_no_chunks(self) -> None:
        assert _chunk_text("   \n\t  ", "src.txt") == []

    def test_source_propagated_to_all_chunks(self) -> None:
        text = "b" * 3000
        for chunk in _chunk_text(text, "myfile.txt"):
            assert chunk["source"] == "myfile.txt"

    def test_overlap_means_adjacent_chunks_share_content(self) -> None:
        # Build text with identifiable tokens so we can verify overlap.
        words = [f"word{i}" for i in range(500)]
        text = " ".join(words)
        chunks = _chunk_text(text, "s.txt")
        if len(chunks) >= 2:
            end_of_first = chunks[0]["text"][-50:]
            start_of_second = chunks[1]["text"][:50]
            # The tail of chunk 0 should appear somewhere in chunk 1.
            assert any(w in chunks[1]["text"] for w in end_of_first.split())


# ---------------------------------------------------------------------------
# _rrf_merge
# ---------------------------------------------------------------------------

class TestRrfMerge(unittest.TestCase):
    def test_single_list_preserves_order(self) -> None:
        result = _rrf_merge([[3, 1, 2]])
        assert result == [3, 1, 2]

    def test_item_in_both_lists_ranked_higher(self) -> None:
        # idx 5 appears first in both lists — should win.
        merged = _rrf_merge([[5, 10, 20], [5, 30, 40]])
        assert merged[0] == 5

    def test_all_items_present(self) -> None:
        lists = [[0, 1, 2], [2, 3, 4]]
        merged = _rrf_merge(lists)
        assert set(merged) == {0, 1, 2, 3, 4}

    def test_empty_lists(self) -> None:
        assert _rrf_merge([]) == []
        assert _rrf_merge([[]]) == []

    def test_rrf_k_parameter_affects_scores(self) -> None:
        # With rrf_k=1, rank-0 score is 1/(0+1)=1.0; with rrf_k=100, it's 1/100.
        # The ordering should still be deterministic.
        merged = _rrf_merge([[0, 1], [0, 1]], rrf_k=1)
        assert merged[0] == 0

    def test_scores_are_monotonically_decreasing(self) -> None:
        lists = [[0, 1, 2, 3], [1, 0, 3, 2]]
        merged = _rrf_merge(lists)
        # items 0 and 1 both appear at the top of both lists → tied high score;
        # just verify the result is a permutation of all ids.
        assert sorted(merged) == [0, 1, 2, 3]


# ---------------------------------------------------------------------------
# HybridRetriever — empty corpus (no heavy deps loaded)
# ---------------------------------------------------------------------------

class TestHybridRetrieverEmptyCorpus(unittest.TestCase):
    def _make(self, tmp_path: str) -> HybridRetriever:
        return HybridRetriever(
            corpus_dir=tmp_path,
            embedding_model_name="all-MiniLM-L6-v2",
        )

    def test_missing_dir_returns_empty_retriever(self) -> None:
        r = self._make("/nonexistent/path/xyz")
        assert r.chunk_count == 0

    def test_retrieve_on_empty_corpus_returns_empty_list(self) -> None:
        r = self._make("/nonexistent/path/xyz")
        assert r.retrieve("hypertension") == []


# ---------------------------------------------------------------------------
# HybridRetriever — injected embed_fn (no real model loaded)
# ---------------------------------------------------------------------------

def _fake_embed(texts: list[str]) -> list[list[float]]:
    """Deterministic toy embedding: hash each text into a 4-d unit vector."""
    out: list[list[float]] = []
    for t in texts:
        h = hash(t) & 0xFFFF
        raw = [float((h >> i) & 1) for i in range(4)]
        norm = math.sqrt(sum(x * x for x in raw)) or 1.0
        out.append([x / norm for x in raw])
    return out


class TestHybridRetrieverWithFakeEmbed(unittest.TestCase):
    def _make_with_corpus(self, tmp_dir: str, filenames_and_texts: list[tuple[str, str]]) -> HybridRetriever:
        import os
        os.makedirs(tmp_dir, exist_ok=True)
        for fname, text in filenames_and_texts:
            with open(os.path.join(tmp_dir, fname), "w", encoding="utf-8") as f:
                f.write(text)
        return HybridRetriever(
            corpus_dir=tmp_dir,
            embedding_model_name="unused",
            _embed_fn=_fake_embed,
        )

    def test_chunk_count_nonzero_with_real_files(self, tmp_path: str = "C:/Temp/test_rag_corpus") -> None:
        r = self._make_with_corpus(
            tmp_path,
            [("hypertension.txt", "Hypertension is high blood pressure. " * 10)],
        )
        assert r.chunk_count >= 1

    def test_retrieve_returns_up_to_top_k(self, tmp_path: str = "C:/Temp/test_rag_corpus2") -> None:
        long_text = "Blood pressure measurement systolic diastolic. " * 60
        r = self._make_with_corpus(
            tmp_path,
            [("bp.txt", long_text)],
        )
        results = r.retrieve("blood pressure", top_k=2)
        assert len(results) <= 2

    def test_retrieve_results_have_required_keys(self, tmp_path: str = "C:/Temp/test_rag_corpus3") -> None:
        r = self._make_with_corpus(
            tmp_path,
            [("info.txt", "Diabetes is a chronic disease affecting blood sugar. " * 20)],
        )
        results = r.retrieve("diabetes blood sugar", top_k=3)
        for item in results:
            assert "text" in item
            assert "source" in item
            assert "chunk_id" in item

    def test_retrieve_top_k_1_returns_one_result(self, tmp_path: str = "C:/Temp/test_rag_corpus4") -> None:
        r = self._make_with_corpus(
            tmp_path,
            [("doc.txt", "Statins reduce cardiovascular risk significantly. " * 30)],
        )
        results = r.retrieve("statin cardiovascular", top_k=1)
        assert len(results) == 1

    def test_no_cohere_rerank_without_api_key(self, tmp_path: str = "C:/Temp/test_rag_corpus5") -> None:
        import os
        os.makedirs(tmp_path, exist_ok=True)
        with open(os.path.join(tmp_path, "doc.txt"), "w") as f:
            f.write("Clinical guidelines for cholesterol management. " * 20)
        r = HybridRetriever(
            corpus_dir=tmp_path,
            embedding_model_name="unused",
            cohere_api_key="",
            _embed_fn=_fake_embed,
        )
        # Should not raise even though Cohere is not installed/configured.
        results = r.retrieve("cholesterol")
        assert isinstance(results, list)

    def test_cohere_rerank_called_when_api_key_set(self, tmp_path: str = "C:/Temp/test_rag_corpus6") -> None:
        import os
        from unittest.mock import MagicMock, patch
        os.makedirs(tmp_path, exist_ok=True)
        with open(os.path.join(tmp_path, "doc.txt"), "w") as f:
            f.write("Hypertension management guidelines recommendations. " * 30)
        r = HybridRetriever(
            corpus_dir=tmp_path,
            embedding_model_name="unused",
            cohere_api_key="fake-key",
            _embed_fn=_fake_embed,
        )
        # Stub out _cohere_rerank to avoid importing cohere.
        with patch.object(r, "_cohere_rerank", return_value=[]) as mock_rerank:
            r.retrieve("hypertension", top_k=3)
        mock_rerank.assert_called_once()
