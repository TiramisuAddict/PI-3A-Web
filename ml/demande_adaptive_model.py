from __future__ import annotations

from typing import Any

from field_planner import generate_response, validate_llm_candidate


def generate_autre_response(request_data: dict[str, Any]) -> dict[str, Any]:
    return generate_response(request_data)


def validate_autre_llm_candidate(request_data: dict[str, Any]) -> dict[str, Any]:
    source = str(request_data.get("text") or request_data.get("prompt") or "").strip()
    candidate = request_data.get("llmCandidate")
    if not isinstance(candidate, dict):
        candidate = {}
    return validate_llm_candidate(source, candidate)
