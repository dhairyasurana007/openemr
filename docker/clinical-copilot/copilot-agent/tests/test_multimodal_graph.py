"""Tests for the LangGraph multi-agent graph (no live LLM or network calls)."""

from __future__ import annotations

import json
import unittest
from unittest.mock import MagicMock, patch

from app.multimodal_graph import (
    CopilotState,
    _content_to_text,
    _extract_first_json_object,
    _escape_control_chars_in_json_strings,
    _last_message_text,
    _llm_json,
    _make_answer_composer,
    _make_evidence_retriever,
    _make_intake_extractor,
    _make_supervisor,
    _merge_usage,
    _route_from_supervisor,
    _strip_fences,
    build_graph,
    run_multimodal_graph,
)
from app.retrieval_backends import StubRetrievalBackend


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _settings():
    from app.settings import Settings
    return Settings(
        openrouter_api_key="test-key",
        openrouter_model="anthropic/claude-3.5-haiku",
        openrouter_model_uc4="",
        openrouter_http_timeout_s=30.0,
        openrouter_http_referer="https://www.open-emr.org/",
        openrouter_app_title="OpenEMR Clinical Co-Pilot",
        clinical_copilot_internal_secret="",
        openemr_fhir_bearer_token="",
        openemr_oauth_token_url=None,
        openemr_oauth_client_id=None,
        openemr_oauth_client_secret=None,
        openemr_oauth_scope=None,
        openemr_oauth_bootstrap_enabled=True,
        openemr_oauth_bootstrap_client_id="clinical-copilot-agent",
        openemr_oauth_bootstrap_scope="api:fhir",
        openemr_internal_hostport="openemr-web:80",
        openemr_standard_api_path_prefix="/apis/default/api",
        openemr_http_verify=True,
        openemr_http_timeout_connect_s=1.0,
        openemr_http_timeout_read_s=2.0,
        openemr_http_max_connections=4,
        openemr_http_max_keepalive=2,
        openemr_max_concurrent_requests=2,
        readyz_probe_openemr=False,
        use_openemr_retrieval=False,
        copilot_max_inflight=0,
        vlm_model="anthropic/claude-sonnet-4.6",
        cohere_api_key="",
        embedding_model="all-MiniLM-L6-v2",
        guidelines_corpus_dir="app/guidelines",
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


def _mock_llm(json_responses: list[dict]) -> MagicMock:
    """Returns a mock LLM whose invoke() cycles through json_responses."""
    call_count = {"n": 0}

    def _invoke(messages):
        resp = json_responses[min(call_count["n"], len(json_responses) - 1)]
        call_count["n"] += 1
        m = MagicMock()
        m.content = json.dumps(resp)
        m.response_metadata = {}
        return m

    llm = MagicMock()
    llm.invoke.side_effect = _invoke
    return llm


def _empty_state(**overrides) -> CopilotState:
    base: CopilotState = {
        "messages": [{"role": "user", "content": "What is the blood pressure target?"}],
        "patient_id": None,
        "extracted_facts": None,
        "guideline_evidence": [],
        "routing_log": [],
        "final_answer": None,
        "citations": [],
        "token_usage": {},
        "_next_node": "supervisor",
    }
    base.update(overrides)  # type: ignore[typeddict-unknown-key]
    return base


# ---------------------------------------------------------------------------
# Pure utility tests
# ---------------------------------------------------------------------------

class TestStripFences(unittest.TestCase):
    def test_no_fence_passthrough(self) -> None:
        assert _strip_fences('{"a": 1}') == '{"a": 1}'

    def test_json_fence_stripped(self) -> None:
        raw = '```json\n{"a": 1}\n```'
        assert _strip_fences(raw) == '{"a": 1}'

    def test_plain_fence_stripped(self) -> None:
        assert _strip_fences("```\n{}\n```") == "{}"

    def test_whitespace_stripped(self) -> None:
        assert _strip_fences("  \n{}\n  ") == "{}"


class TestJsonSanitize(unittest.TestCase):
    def test_escapes_raw_newline_inside_json_string(self) -> None:
        raw = '{"reply":"line1\nline2","citations":[]}'
        repaired = _escape_control_chars_in_json_strings(raw)
        parsed = json.loads(repaired)
        assert parsed["reply"] == "line1\nline2"

    def test_content_list_to_text(self) -> None:
        content = [{"type": "text", "text": '{"reply":"ok","citations":[]}'}]
        assert _content_to_text(content) == '{"reply":"ok","citations":[]}'

    def test_extracts_first_json_object_from_mixed_text(self) -> None:
        raw = 'Sure, here is JSON: {"decision":"answer","reason":"enough"} trailing text'
        extracted = _extract_first_json_object(raw)
        assert extracted == '{"decision":"answer","reason":"enough"}'

    def test_llm_json_fallback_key_wraps_plaintext(self) -> None:
        llm = MagicMock()
        response = MagicMock()
        response.content = "Plain text answer"
        response.response_metadata = {}
        llm.invoke.return_value = response
        parsed, usage = _llm_json(llm, "system", "user", fallback_key="reply")
        assert parsed["reply"] == "Plain text answer"
        assert usage["total_tokens"] == 0

    def test_llm_json_parses_embedded_json(self) -> None:
        llm = MagicMock()
        response = MagicMock()
        response.content = 'Prefix text {"reply":"ok","citations":[]} suffix'
        response.response_metadata = {}
        llm.invoke.return_value = response
        parsed, _ = _llm_json(llm, "system", "user")
        assert parsed["reply"] == "ok"


class TestMergeUsage(unittest.TestCase):
    def test_adds_fields(self) -> None:
        result = _merge_usage({"prompt_tokens": 10}, {"prompt_tokens": 5, "completion_tokens": 3})
        assert result["prompt_tokens"] == 15
        assert result["completion_tokens"] == 3

    def test_empty_existing(self) -> None:
        result = _merge_usage({}, {"total_tokens": 7})
        assert result["total_tokens"] == 7

    def test_does_not_mutate_input(self) -> None:
        existing = {"prompt_tokens": 1}
        _merge_usage(existing, {"prompt_tokens": 2})
        assert existing["prompt_tokens"] == 1


class TestLastMessageText(unittest.TestCase):
    def test_dict_message(self) -> None:
        state = _empty_state(messages=[{"role": "user", "content": "hello"}])
        assert _last_message_text(state) == "hello"

    def test_object_with_content(self) -> None:
        m = MagicMock()
        m.content = "world"
        state = _empty_state(messages=[m])
        assert _last_message_text(state) == "world"

    def test_empty_messages(self) -> None:
        assert _last_message_text(_empty_state(messages=[])) == ""


class TestRouteFromSupervisor(unittest.TestCase):
    def test_returns_next_node(self) -> None:
        state = _empty_state(_next_node="intake_extractor")  # type: ignore[call-arg]
        assert _route_from_supervisor(state) == "intake_extractor"

    def test_defaults_to_answer_composer(self) -> None:
        state = _empty_state()
        state["_next_node"] = ""
        assert _route_from_supervisor(state) == "answer_composer"


# ---------------------------------------------------------------------------
# Supervisor node
# ---------------------------------------------------------------------------

class TestSupervisorNode(unittest.TestCase):
    def test_no_patient_context_routes_to_chart_retriever_first(self) -> None:
        llm = _mock_llm([{"decision": "chart_retriever", "reason": "needs chart"}])
        result = _make_supervisor(llm)(_empty_state(patient_id=None))
        assert result["_next_node"] == "chart_retriever"
        assert result["routing_log"][-1]["decision"] == "chart_retriever"

    def test_no_patient_extracted_facts_still_routes_to_chart_retriever_first(self) -> None:
        llm = _mock_llm([])
        supervisor = _make_supervisor(llm)
        state = _empty_state(
            patient_id=None,
            extracted_facts={"doc_type": "lab", "results": []},
        )
        result = supervisor(state)
        assert result["_next_node"] == "chart_retriever"
        assert result["routing_log"][-1]["decision"] == "chart_retriever"
        llm.invoke.assert_not_called()

    def test_routes_to_intake_extractor(self) -> None:
        llm = _mock_llm([{"decision": "intake_extractor", "reason": "facts present"}])
        supervisor = _make_supervisor(llm)
        state = _empty_state(patient_id="p-1", extracted_facts={"doc_type": "lab"})
        result = supervisor(state)
        assert result["_next_node"] == "intake_extractor"
        assert len(result["routing_log"]) == 1
        assert result["routing_log"][0]["node"] == "supervisor"

    def test_routes_to_evidence_retriever(self) -> None:
        llm = _mock_llm([{"decision": "evidence_retriever", "reason": "needs guidelines"}])
        result = _make_supervisor(llm)(_empty_state(patient_id="p-1"))
        assert result["_next_node"] == "evidence_retriever"

    def test_routes_to_answer_composer_on_answer_decision(self) -> None:
        llm = _mock_llm([{"decision": "answer", "reason": "enough context"}])
        result = _make_supervisor(llm)(_empty_state(patient_id="p-1"))
        assert result["_next_node"] == "answer_composer"

    def test_forces_answer_after_max_routing_steps(self) -> None:
        llm = _mock_llm([{"decision": "intake_extractor", "reason": "x"}])
        state = _empty_state(patient_id="p-1")
        state["routing_log"] = [{"node": "supervisor"}] * 6
        result = _make_supervisor(llm)(state)
        assert result["_next_node"] == "answer_composer"
        assert "maximum" in result["routing_log"][-1]["reason"]

    def test_falls_back_on_llm_error(self) -> None:
        llm = MagicMock()
        llm.invoke.side_effect = RuntimeError("network down")
        result = _make_supervisor(llm)(_empty_state(patient_id="p-1"))
        assert result["_next_node"] == "answer_composer"

    def test_unknown_decision_maps_to_answer_composer(self) -> None:
        llm = _mock_llm([{"decision": "unknown_worker", "reason": "oops"}])
        result = _make_supervisor(llm)(_empty_state(patient_id="p-1"))
        assert result["_next_node"] == "answer_composer"

    def test_routing_log_appended(self) -> None:
        llm = _mock_llm([{"decision": "answer", "reason": "done"}])
        state = _empty_state(patient_id="p-1")
        state["routing_log"] = [{"node": "prior"}]
        result = _make_supervisor(llm)(state)
        assert len(result["routing_log"]) == 2

    def test_timestamp_ms_present(self) -> None:
        llm = _mock_llm([{"decision": "answer", "reason": "x"}])
        result = _make_supervisor(llm)(_empty_state(patient_id="p-1"))
        assert "timestamp_ms" in result["routing_log"][-1]


# ---------------------------------------------------------------------------
# Intake extractor node
# ---------------------------------------------------------------------------

class TestIntakeExtractorNode(unittest.TestCase):
    def test_appends_intake_summary_to_messages(self) -> None:
        llm = _mock_llm([{"summary": "Patient has hypertension.", "citations": []}])
        state = _empty_state(extracted_facts={"doc_type": "intake_form", "chief_concern": "chest pain"})
        result = _make_intake_extractor(llm)(state)
        assert any("[Intake summary]" in str(m.get("content", "")) for m in result["messages"])

    def test_citations_appended(self) -> None:
        citation = {
            "source_type": "intake_form",
            "source_id": "sha256:abc",
            "page_or_section": "page 1",
            "field_or_chunk_id": "chief_concern",
            "quote_or_value": "chest pain",
        }
        llm = _mock_llm([{"summary": "Pain noted.", "citations": [citation]}])
        result = _make_intake_extractor(llm)(_empty_state(extracted_facts={}))
        assert len(result["citations"]) == 1

    def test_routing_log_entry_added(self) -> None:
        llm = _mock_llm([{"summary": "ok", "citations": []}])
        result = _make_intake_extractor(llm)(_empty_state())
        assert result["routing_log"][-1]["node"] == "intake_extractor"

    def test_handles_llm_error_gracefully(self) -> None:
        llm = MagicMock()
        llm.invoke.side_effect = RuntimeError("timeout")
        result = _make_intake_extractor(llm)(_empty_state())
        assert isinstance(result["citations"], list)


# ---------------------------------------------------------------------------
# Evidence retriever node
# ---------------------------------------------------------------------------

class TestEvidenceRetrieverNode(unittest.TestCase):
    def test_stores_snippets_in_guideline_evidence(self) -> None:
        rag = MagicMock()
        rag.retrieve.return_value = [
            {"text": "Hypertension target is < 130/80 mmHg.", "source": "uspstf.txt", "chunk_id": 0}
        ]
        result = _make_evidence_retriever(rag)(_empty_state())
        assert len(result["guideline_evidence"]) == 1
        assert result["guideline_evidence"][0]["source"] == "uspstf.txt"

    def test_citations_derived_from_snippets(self) -> None:
        rag = MagicMock()
        rag.retrieve.return_value = [{"text": "test", "source": "guide.txt", "chunk_id": 3}]
        result = _make_evidence_retriever(rag)(_empty_state())
        assert len(result["citations"]) == 1
        assert result["citations"][0]["source_type"] == "guideline"
        assert result["citations"][0]["source_id"] == "guide.txt"

    def test_none_rag_returns_empty(self) -> None:
        result = _make_evidence_retriever(None)(_empty_state())
        assert result["guideline_evidence"] == []

    def test_rag_error_returns_empty(self) -> None:
        rag = MagicMock()
        rag.retrieve.side_effect = RuntimeError("index not ready")
        result = _make_evidence_retriever(rag)(_empty_state())
        assert result["guideline_evidence"] == []

    def test_routing_log_entry_added(self) -> None:
        result = _make_evidence_retriever(None)(_empty_state())
        assert result["routing_log"][-1]["node"] == "evidence_retriever"

    def test_rag_runs_without_uploaded_document(self) -> None:
        rag = MagicMock()
        rag.retrieve.return_value = []
        result = _make_evidence_retriever(rag)(_empty_state(extracted_facts=None))
        rag.retrieve.assert_called_once()
        assert isinstance(result["guideline_evidence"], list)


# ---------------------------------------------------------------------------
# Answer composer node
# ---------------------------------------------------------------------------

class TestAnswerComposerNode(unittest.TestCase):
    def test_sets_final_answer(self) -> None:
        llm = _mock_llm([{"reply": "The target BP is < 130/80.", "citations": []}])
        result = _make_answer_composer(llm)(_empty_state())
        assert result["final_answer"] == "The target BP is < 130/80."

    def test_citations_appended(self) -> None:
        cit = {
            "source_type": "guideline",
            "source_id": "uspstf.txt",
            "page_or_section": "chunk_0",
            "field_or_chunk_id": "0",
            "quote_or_value": "target < 130/80",
        }
        llm = _mock_llm([{"reply": "ok", "citations": [cit]}])
        result = _make_answer_composer(llm)(_empty_state())
        assert len(result["citations"]) == 1

    def test_handles_llm_error_gracefully(self) -> None:
        llm = MagicMock()
        llm.invoke.side_effect = RuntimeError("timeout")
        result = _make_answer_composer(llm)(_empty_state())
        assert isinstance(result["final_answer"], str)
        assert len(result["final_answer"]) > 0

    def test_uses_extracted_facts_in_prompt(self) -> None:
        llm = _mock_llm([{"reply": "Based on the lab results...", "citations": []}])
        state = _empty_state(extracted_facts={"doc_type": "lab", "results": []})
        _make_answer_composer(llm)(state)
        call_args = llm.invoke.call_args[0][0]
        content = " ".join(str(m.content) for m in call_args)
        assert "Extracted facts:" in content

    def test_no_extracted_facts_in_prompt_when_intake_summary_present(self) -> None:
        llm = _mock_llm([{"reply": "Based on intake...", "citations": []}])
        state = _empty_state(
            extracted_facts={"doc_type": "lab", "results": []},
            intake_summary="Patient has hypertension.",
        )
        _make_answer_composer(llm)(state)
        call_args = llm.invoke.call_args[0][0]
        content = " ".join(str(m.content) for m in call_args)
        assert "Extracted facts:" not in content

    def test_routing_log_entry_added(self) -> None:
        llm = _mock_llm([{"reply": "done", "citations": []}])
        result = _make_answer_composer(llm)(_empty_state())
        assert result["routing_log"][-1]["node"] == "answer_composer"

    def test_falls_back_to_existing_state_citations(self) -> None:
        llm = _mock_llm([{"reply": "done", "citations": []}])
        state = _empty_state(
            citations=[{
                "source_type": "intake_form",
                "source_id": "sha256:abc",
                "page_or_section": "page 1",
                "field_or_chunk_id": "chief_concern",
                "quote_or_value": "headache",
            }]
        )
        result = _make_answer_composer(llm)(state)
        assert len(result["citations"]) == 1


# ---------------------------------------------------------------------------
# Full graph integration (mocked LLM — no OpenRouter calls)
# ---------------------------------------------------------------------------

class TestBuildAndRunGraph(unittest.TestCase):
    def _run(self, llm_responses: list[dict], **state_kwargs) -> dict:
        llm = _mock_llm(llm_responses)
        settings = _settings()
        with patch(
            "app.multimodal_graph.run_chat_with_tools",
            return_value=(
                "Stub chart retrieval reply.",
                {"tool_payloads": [{"tool": "get_observations", "retrieval_status": {"ok": True}}], "tools_used": []},
            ),
        ):
            return run_multimodal_graph(
                message="What is the BP target?",
                settings=settings,
                backend=StubRetrievalBackend(),
                rag_retriever=None,
                _llm=llm,
                **state_kwargs,
            )

    def test_returns_reply_string(self) -> None:
        result = self._run([
            {"decision": "answer", "reason": "enough context"},
            {"reply": "Target is < 130/80.", "citations": []},
        ])
        assert isinstance(result["reply"], str)
        assert len(result["reply"]) > 0

    def test_returns_routing_log(self) -> None:
        result = self._run([
            {"decision": "answer", "reason": "x"},
            {"reply": "ok", "citations": []},
        ])
        assert isinstance(result["routing_log"], list)
        assert len(result["routing_log"]) >= 2  # supervisor + answer_composer

    def test_returns_citations_list(self) -> None:
        result = self._run([
            {"decision": "answer", "reason": "x"},
            {"reply": "ok", "citations": []},
        ])
        assert isinstance(result["citations"], list)

    def test_returns_token_usage_dict(self) -> None:
        result = self._run([
            {"decision": "answer", "reason": "x"},
            {"reply": "ok", "citations": []},
        ])
        assert isinstance(result["token_usage"], dict)

    def test_intake_extractor_runs_when_facts_present(self) -> None:
        # No patient_id: supervisor now routes chart_retriever first, then may run
        # intake_extractor/evidence_retriever before answer composition.
        result = self._run(
            [
                {"decision": "intake_extractor", "reason": "summarize upload"},
                {"summary": "Patient has HTN.", "citations": []},  # intake_extractor
                {"reply": "Based on extracted facts...", "citations": []},  # answer_composer
            ],
            extracted_facts={"doc_type": "lab"},
        )
        routing_nodes = [e["node"] for e in result["routing_log"]]
        assert "intake_extractor" in routing_nodes

    def test_evidence_retriever_runs_when_rag_available(self) -> None:
        rag = MagicMock()
        rag.retrieve.return_value = [{"text": "BP target < 130.", "source": "uspstf.txt", "chunk_id": 0}]
        llm = _mock_llm([
            {"decision": "evidence_retriever", "reason": "needs guidelines"},
            {"decision": "answer", "reason": "evidence retrieved"},
            {"reply": "Guideline says...", "citations": []},
        ])
        with patch(
            "app.multimodal_graph.run_chat_with_tools",
            return_value=(
                "Stub chart retrieval reply.",
                {"tool_payloads": [{"tool": "get_observations", "retrieval_status": {"ok": True}}], "tools_used": []},
            ),
        ):
            result = run_multimodal_graph(
                message="What is the BP target?",
                settings=_settings(),
                backend=StubRetrievalBackend(),
                rag_retriever=rag,
                _llm=llm,
            )
        routing_nodes = [e["node"] for e in result["routing_log"]]
        assert "evidence_retriever" in routing_nodes
        rag.retrieve.assert_called_once()

    def test_evidence_retriever_runs_after_upload(self) -> None:
        # No patient_id + extracted_facts: chart_retriever runs first. With the
        # current worker-hop budget, intake_extractor may run next and the graph
        # can finalize without an evidence step.
        rag = MagicMock()
        rag.retrieve.return_value = [{"text": "BP target < 130.", "source": "uspstf.txt", "chunk_id": 0}]
        llm = _mock_llm([
            {"decision": "intake_extractor", "reason": "summarize upload"},
            {"summary": "Lab results reviewed.", "citations": []},  # intake_extractor
            {"decision": "evidence_retriever", "reason": "needs guideline support"},
            {"reply": "Guideline says...", "citations": []},        # answer_composer
        ])
        with patch(
            "app.multimodal_graph.run_chat_with_tools",
            return_value=(
                "Stub chart retrieval reply.",
                {"tool_payloads": [{"tool": "get_observations", "retrieval_status": {"ok": True}}], "tools_used": []},
            ),
        ):
            result = run_multimodal_graph(
                message="What is the BP target?",
                settings=_settings(),
                backend=StubRetrievalBackend(),
                rag_retriever=rag,
                extracted_facts={"doc_type": "lab", "results": []},
                _llm=llm,
            )
        routing_nodes = [e["node"] for e in result["routing_log"]]
        assert "intake_extractor" in routing_nodes
        assert "answer_composer" in routing_nodes

    def test_initializes_citations_from_extracted_facts(self) -> None:
        llm = _mock_llm([
            {"decision": "answer", "reason": "enough context"},
            {"reply": "Done", "citations": []},
        ])
        extracted = {
            "doc_type": "intake_form",
            "citation": {
                "source_type": "intake_form",
                "source_id": "sha256:abc",
                "page_or_section": "page 1",
                "field_or_chunk_id": "intake_form_summary",
                "quote_or_value": "Patient intake",
            },
        }
        result = run_multimodal_graph(
            message="Summarize this upload.",
            settings=_settings(),
            backend=StubRetrievalBackend(),
            extracted_facts=extracted,
            _llm=llm,
        )
        assert len(result["citations"]) >= 1

    def test_graph_terminates_on_max_steps(self) -> None:
        # LLM always says intake_extractor — graph must terminate via step guard.
        llm = _mock_llm([
            {"decision": "intake_extractor", "reason": "always"},
            {"summary": "ok", "citations": []},
        ] * 10)
        result = run_multimodal_graph(
            message="test",
            settings=_settings(),
            backend=StubRetrievalBackend(),
            extracted_facts={"doc_type": "lab"},
            _llm=llm,
        )
        assert isinstance(result["reply"], str)
