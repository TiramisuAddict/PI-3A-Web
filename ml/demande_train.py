from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path
from typing import Any

import demande_ai_model as model


def _load_json_file(path: Path) -> Any:
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def _load_jsonl_file(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    rows: list[dict[str, Any]] = []
    try:
        with path.open("r", encoding="utf-8") as handle:
            for line in handle:
                line = line.strip()
                if not line:
                    continue
                try:
                    payload = json.loads(line)
                except json.JSONDecodeError:
                    continue
                if isinstance(payload, dict):
                    rows.append(payload)
    except OSError:
        return []
    return rows


def _normalize_record(record: dict[str, Any]) -> dict[str, Any] | None:
    normalized = model._normalize_autre_training_record(record)
    return normalized if isinstance(normalized, dict) else None


def _load_db_samples(db_path: Path) -> list[dict[str, Any]]:
    payload = _load_json_file(db_path)
    if not isinstance(payload, list):
        return []

    samples: list[dict[str, Any]] = []
    for record in payload:
        if not isinstance(record, dict):
            continue
        normalized = _normalize_record(record)
        if normalized:
            normalized["source"] = "db"
            samples.append(normalized)
    return samples


def _load_feedback_samples(feedback_path: Path) -> list[dict[str, Any]]:
    rows = _load_jsonl_file(feedback_path)
    samples: list[dict[str, Any]] = []
    for record in rows:
        normalized = _normalize_record(record)
        if normalized:
            normalized["source"] = "feedback"
            samples.append(normalized)
    return samples


def _summarize_value_counts(miner: model.dyn_extract.PatternMiner, top_n: int = 5) -> dict[str, list[dict[str, Any]]]:
    summary: dict[str, list[dict[str, Any]]] = {}
    for field_key, counts in miner.value_counts.items():
        if not isinstance(counts, Counter):
            counts = Counter(counts)
        summary[field_key] = [
            {"value": value, "count": count}
            for value, count in counts.most_common(top_n)
        ]
    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description="Train the Autre PatternMiner from DB and confirmed feedback")
    parser.add_argument("--db-file", default=None, help="Path to var/ai/db_demandes_with_details.json")
    parser.add_argument("--feedback-file", default=None, help="Path to var/ai/autre_generation_feedback.jsonl")
    parser.add_argument("--output-file", default=None, help="Path to var/ai/autre_pattern_miner.json")
    parser.add_argument("--report-file", default=None, help="Path to var/ai/demande_training_report.json")
    args = parser.parse_args()

    data_dir = model._autre_data_dir()
    db_path = Path(args.db_file) if args.db_file else model._autre_db_training_path()
    feedback_path = Path(args.feedback_file) if args.feedback_file else model._autre_feedback_path()
    output_path = Path(args.output_file) if args.output_file else model._autre_pattern_miner_path()
    report_path = Path(args.report_file) if args.report_file else data_dir / "demande_training_report.json"

    db_samples = _load_db_samples(db_path)
    feedback_samples = _load_feedback_samples(feedback_path)
    samples = db_samples + feedback_samples

    miner = model.dyn_extract.PatternMiner()
    miner.fit(samples)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    miner.save(output_path)

    report = {
        "ok": True,
        "version": miner.version,
        "trained_at": miner.trained_at,
        "db_samples": len(db_samples),
        "feedback_samples": len(feedback_samples),
        "total_samples": miner.total_samples,
        "unique_fields": len(miner.value_counts),
        "field_cooccurrence_fields": len(miner.field_cooccurrence),
        "priority_signals": miner.priority_signals,
        "value_counts_top": _summarize_value_counts(miner),
        "field_cooccurrence": miner.field_cooccurrence,
        "miner_file": str(output_path),
    }

    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps(report, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
