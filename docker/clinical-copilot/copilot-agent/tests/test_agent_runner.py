"""Agent two-phase tests with mocked LLM (no OpenRouter)."""

from __future__ import annotations

from unittest.mock import MagicMock

from langchain_core.messages import AIMessage
from langchain_core.language_models import BaseChatModel

from app.agent_runner import run_chat_with_tools
from app.retrieval_backends import StubRetrievalBackend
from app.settings import Settings


def _minimal_settings() -> Settings:
    return Settings(
        openrouter_api_key="dummy",
        openrouter_model="anthropic/claude-3.5-haiku",
        openrouter_model_uc4="",
        openrouter_http_timeout_s=5.0,
        openrouter_http_referer="https://www.open-emr.org/",
        openrouter_app_title="OpenEMR Clinical Co-Pilot",
        clinical_copilot_internal_secret="",
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
        langchain_api_key="",
        langchain_tracing_v2=False,
        langchain_project="clinical-copilot",
        langchain_endpoint="",
    )


def _configure_two_phase_mock(
    *,
    msg_tools: AIMessage,
    msg_planner_no_tools: AIMessage,
    msg_final: AIMessage,
) -> MagicMock:
    base = MagicMock(spec=BaseChatModel)
    bound_req = MagicMock()
    bound_auto = MagicMock()
    base.bind_tools.side_effect = [bound_req, bound_auto]
    bound_req.invoke.side_effect = [msg_tools]
    bound_auto.invoke.side_effect = [msg_planner_no_tools]
    base.invoke.side_effect = [msg_final]
    return base


def test_two_phase_retrieval_then_grounded_summary() -> None:
    backend = StubRetrievalBackend()
    msg_tools = AIMessage(
        content="",
        tool_calls=[
            {
                "name": "get_observations",
                "args": {"patient_uuid": "00000000-0000-4000-8000-0000000000aa"},
                "id": "call_obs",
                "type": "tool_call",
            }
        ],
    )
    msg_planner = AIMessage(content="", tool_calls=[])
    msg_final = AIMessage(
        content="RETRIEVED_JSON has no vitals or laboratory rows; nothing numeric to report."
    )
    base = _configure_two_phase_mock(msg_tools=msg_tools, msg_planner_no_tools=msg_planner, msg_final=msg_final)

    def factory(_s: Settings) -> BaseChatModel:
        return base  # type: ignore[return-value]

    text, diag = run_chat_with_tools(
        "What was the glucose?",
        _minimal_settings(),
        backend,
        llm_factory=factory,
    )

    assert "no vitals" in text.lower() or "nothing" in text.lower() or "empty" in text.lower()
    assert diag["tool_payload_count"] >= 1
    assert diag["verification"]["grounding_ok"] is True
    assert diag.get("summarization_mode") == "json_only_two_phase"
    assert diag.get("tools_used") == [
        {
            "name": "get_observations",
            "args": {"patient_uuid": "00000000-0000-4000-8000-0000000000aa"},
            "tool_call_id": "call_obs",
            "status": "ok",
        }
    ]
    assert base.bind_tools.call_count == 2
    assert base.invoke.call_count == 1


def test_uc4_uses_sonnet_default_model() -> None:
    backend = StubRetrievalBackend()
    msg_tools = AIMessage(
        content="",
        tool_calls=[
            {
                "name": "get_observations",
                "args": {"patient_uuid": "00000000-0000-4000-8000-0000000000aa"},
                "id": "call_obs",
                "type": "tool_call",
            }
        ],
    )
    msg_planner = AIMessage(content="", tool_calls=[])
    msg_final = AIMessage(content="No findings.")
    base = _configure_two_phase_mock(msg_tools=msg_tools, msg_planner_no_tools=msg_planner, msg_final=msg_final)

    def factory(_s: Settings) -> BaseChatModel:
        return base  # type: ignore[return-value]

    _, diag = run_chat_with_tools(
        "Any recent labs?",
        _minimal_settings(),
        backend,
        llm_factory=factory,
        use_case="UC4",
    )

    assert diag["openrouter_model_effective"] == "anthropic/claude-sonnet-4.5"


def test_failed_tool_still_runs_phase2_with_footer_or_disclosure() -> None:
    backend = StubRetrievalBackend(fail_tool="get_observations")
    msg_tools = AIMessage(
        content="",
        tool_calls=[
            {
                "name": "get_observations",
                "args": {"patient_uuid": "x"},
                "id": "call_obs",
                "type": "tool_call",
            }
        ],
    )
    msg_planner = AIMessage(content="", tool_calls=[])
    msg_final = AIMessage(content="Retrieval failed for observations; no glucose value in JSON.")
    base = _configure_two_phase_mock(msg_tools=msg_tools, msg_planner_no_tools=msg_planner, msg_final=msg_final)

    def factory(_s: Settings) -> BaseChatModel:
        return base  # type: ignore[return-value]

    text, diag = run_chat_with_tools(
        "Glucose?",
        _minimal_settings(),
        backend,
        llm_factory=factory,
    )

    assert "failed" in text.lower() or diag["verification"]["tool_failures_disclosed_ok"] is True
    assert diag.get("summarization_mode") == "json_only_two_phase"


def test_aborts_when_first_turn_returns_no_tool_calls() -> None:
    backend = StubRetrievalBackend()
    msg_bad = AIMessage(content="I refuse to use tools.", tool_calls=[])
    bound_req = MagicMock()
    bound_auto = MagicMock()
    base = MagicMock(spec=BaseChatModel)
    base.bind_tools.side_effect = [bound_req, bound_auto]
    bound_req.invoke.side_effect = [msg_bad]

    def factory(_s: Settings) -> BaseChatModel:
        return base  # type: ignore[return-value]

    text, diag = run_chat_with_tools(
        "What are labs for this patient?",
        _minimal_settings(),
        backend,
        llm_factory=factory,
    )

    assert "No summary" in text or "retrieval" in text.lower()
    assert diag.get("summarization_mode") == "aborted_no_retrieval"
    assert any(f.get("code") == "retrieval_tool_calls_missing" for f in diag.get("verification_findings", []))
    assert base.invoke.call_count == 0
