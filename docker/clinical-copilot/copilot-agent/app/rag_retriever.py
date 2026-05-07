"""Hybrid RAG retriever for clinical guideline corpus.

Pipeline:
  1. BM25 sparse search (rank_bm25) over 300-token chunks with 50-token overlap.
  2. Dense vector search (sentence-transformers all-MiniLM-L6-v2 by default).
  3. Reciprocal Rank Fusion to merge candidate sets.
  4. Optional Cohere Rerank if COHERE_API_KEY is configured; otherwise top-k from RRF.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any, Callable

import numpy as np

_LOG = logging.getLogger("clinical_copilot.rag_retriever")

# Character-window chunking: ~300 tokens × 4 chars/token.
_CHUNK_CHARS = 1200
_OVERLAP_CHARS = 200  # ~50 tokens overlap
_STEP_CHARS = _CHUNK_CHARS - _OVERLAP_CHARS

EmbedFn = Callable[[list[str]], list[list[float]]]


# ---------------------------------------------------------------------------
# Chunking
# ---------------------------------------------------------------------------

def _chunk_text(text: str, source: str) -> list[dict[str, Any]]:
    """Split *text* into overlapping character-window chunks."""
    chunks: list[dict[str, Any]] = []
    chunk_id = 0
    start = 0
    while start < len(text):
        end = min(start + _CHUNK_CHARS, len(text))
        snippet = text[start:end].strip()
        if snippet:
            chunks.append({"text": snippet, "source": source, "chunk_id": chunk_id})
            chunk_id += 1
        start += _STEP_CHARS
    return chunks


# ---------------------------------------------------------------------------
# RRF
# ---------------------------------------------------------------------------

def _rrf_merge(ranked_lists: list[list[int]], rrf_k: int = 60) -> list[int]:
    """Reciprocal Rank Fusion over multiple ranked index lists."""
    scores: dict[int, float] = {}
    for ranked in ranked_lists:
        for rank, idx in enumerate(ranked):
            scores[idx] = scores.get(idx, 0.0) + 1.0 / (rank + rrf_k)
    return sorted(scores.keys(), key=lambda i: scores[i], reverse=True)


# ---------------------------------------------------------------------------
# Embedding loader
# ---------------------------------------------------------------------------

def _load_sentence_transformer(model_name: str) -> EmbedFn:
    from sentence_transformers import SentenceTransformer  # lazy — heavy import

    model = SentenceTransformer(model_name)

    def _embed(texts: list[str]) -> list[list[float]]:
        return model.encode(texts, normalize_embeddings=True).tolist()

    return _embed


# ---------------------------------------------------------------------------
# HybridRetriever
# ---------------------------------------------------------------------------

class HybridRetriever:
    """Sparse + dense retriever over the clinical guideline corpus."""

    def __init__(
        self,
        corpus_dir: str,
        embedding_model_name: str,
        cohere_api_key: str = "",
        _embed_fn: EmbedFn | None = None,
    ) -> None:
        self._cohere_api_key = cohere_api_key
        self._chunks = self._load_corpus(corpus_dir)

        if not self._chunks:
            self._bm25: Any = None
            self._embeddings: np.ndarray | None = None
            self._embed_fn: EmbedFn | None = _embed_fn
            _LOG.info("rag_retriever_indexed chunk_count=0 corpus_dir=%s", corpus_dir)
            return

        # Lazy import of rank_bm25 — only needed when corpus is non-empty.
        from rank_bm25 import BM25Okapi  # type: ignore[import-untyped]

        tokenized = [c["text"].split() for c in self._chunks]
        self._bm25 = BM25Okapi(tokenized)

        self._embed_fn = _embed_fn or _load_sentence_transformer(embedding_model_name)
        raw = self._embed_fn([c["text"] for c in self._chunks])
        mat = np.array(raw, dtype=np.float32)
        norms = np.linalg.norm(mat, axis=1, keepdims=True)
        self._embeddings = mat / np.where(norms > 0, norms, 1.0)

        _LOG.info(
            "rag_retriever_indexed chunk_count=%d corpus_dir=%s embedding_dim=%d",
            len(self._chunks),
            corpus_dir,
            self._embeddings.shape[1],
        )

    @property
    def chunk_count(self) -> int:
        return len(self._chunks)

    @staticmethod
    def _load_corpus(corpus_dir: str) -> list[dict[str, Any]]:
        import json as _json

        path = Path(corpus_dir)
        if not path.is_dir():
            _LOG.warning("rag_retriever_corpus_dir_missing path=%s", corpus_dir)
            return []

        # Build filename → {url, description} from sources.json when present.
        source_meta: dict[str, dict[str, str]] = {}
        sources_path = path / "sources.json"
        if sources_path.exists():
            try:
                for entry in _json.loads(sources_path.read_text(encoding="utf-8")):
                    fname = entry.get("filename", "")
                    if fname:
                        source_meta[fname] = {
                            "url": entry.get("url", ""),
                            "description": entry.get("description", fname),
                        }
            except Exception:
                _LOG.warning("rag_retriever_sources_json_unreadable path=%s", sources_path)

        chunks: list[dict[str, Any]] = []
        for txt_file in sorted(path.glob("*.txt")):
            text = txt_file.read_text(encoding="utf-8", errors="replace")
            meta = source_meta.get(txt_file.name, {"url": "", "description": txt_file.name})
            for chunk in _chunk_text(text, txt_file.name):
                chunk["url"] = meta["url"]
                chunk["description"] = meta["description"]
                chunks.append(chunk)
        return chunks

    def retrieve(self, query: str, top_k: int = 5) -> list[dict[str, Any]]:
        """Return up to *top_k* guideline chunks most relevant to *query*."""
        if not self._chunks or self._bm25 is None or self._embed_fn is None:
            return []

        candidate_k = min(top_k * 3, len(self._chunks))

        # BM25 sparse
        bm25_scores: np.ndarray = self._bm25.get_scores(query.split())
        bm25_top = list(np.argsort(bm25_scores)[::-1][:candidate_k])

        # Dense cosine
        assert self._embeddings is not None
        q_raw = self._embed_fn([query])[0]
        q_emb = np.array(q_raw, dtype=np.float32)
        q_norm = float(np.linalg.norm(q_emb))
        if q_norm > 0:
            q_emb /= q_norm
        sims: np.ndarray = self._embeddings @ q_emb
        dense_top = list(np.argsort(sims)[::-1][:candidate_k])

        merged = _rrf_merge([bm25_top, dense_top])[:top_k]
        candidates = [self._chunks[i] for i in merged]

        if self._cohere_api_key and len(candidates) > 1:
            return self._cohere_rerank(query, candidates, top_k)

        return candidates

    def _cohere_rerank(
        self, query: str, candidates: list[dict[str, Any]], top_k: int
    ) -> list[dict[str, Any]]:
        import cohere  # lazy — optional dep

        client = cohere.Client(self._cohere_api_key)
        result = client.rerank(
            query=query,
            documents=[c["text"] for c in candidates],
            model="rerank-english-v3.0",
            top_n=top_k,
        )
        return [candidates[r.index] for r in result.results]
