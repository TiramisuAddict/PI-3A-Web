from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import dataclass, replace
from datetime import datetime
import json
from pathlib import Path
import re
from typing import Any, Iterable

from extractors import STOPWORDS, normalize_ws, norm, sentence_case, tokenize


BASE_DETAIL_KEYS = {
    "besoinPersonnalise",
    "descriptionBesoin",
    "niveauUrgenceAutre",
    "dateSouhaiteeAutre",
    "pieceOuContexte",
}

LONG_TEXT_HINTS = {
    "description",
    "detail",
    "details",
    "justification",
    "motif",
    "raison",
    "contexte",
    "usage",
    "commentaire",
}


@dataclass(frozen=True)
class FieldSpec:
    key: str
    label: str
    type: str = "text"
    required: bool = False
    value: str = ""
    options: tuple[str, ...] = ()
    source: str = "learned"
    explicit: bool = False
    confidence: float = 0.0

    def with_value(self, value: str, *, confidence: float | None = None, explicit: bool | None = None, source: str | None = None) -> "FieldSpec":
        return replace(
            self,
            value=normalize_ws(value),
            confidence=self.confidence if confidence is None else confidence,
            explicit=self.explicit if explicit is None else explicit,
            source=self.source if source is None else source,
        )

    def to_dict(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "key": self.key,
            "label": self.label,
            "type": self.type if self.type in {"text", "textarea", "select", "number", "date"} else "text",
            "required": bool(self.required),
            "value": self.value,
            "source": self.source,
        }
        if payload["type"] == "select":
            payload["options"] = list(self.options)
        return payload


@dataclass(frozen=True)
class TrainingSample:
    prompt: str
    general: dict[str, Any]
    details: dict[str, str]
    field_plan: dict[str, Any]
    fields: tuple[FieldSpec, ...]
    confirmed: bool = True
    manual: bool = False
    created_at: datetime = datetime.min
    learning_source: str = ""
    generated_snapshot: dict[str, Any] | None = None


@dataclass(frozen=True)
class LearningProfile:
    samples: tuple[TrainingSample, ...]
    field_frequency: Counter[str]
    field_cooccurrence: dict[str, Counter[str]]
    value_patterns: dict[str, Counter[str]]
    evidence_terms: dict[str, Counter[str]]
    suppressed_signatures: set[str]


def parse_timestamp(value: Any) -> datetime:
    raw = normalize_ws(value)
    if not raw:
        return datetime.min
    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00")).replace(tzinfo=None)
    except Exception:
        return datetime.min


def to_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return int(value) == 1
    return norm(value) in {"1", "true", "yes", "oui", "on"}


def normalize_options(value: Any) -> tuple[str, ...]:
    if not isinstance(value, list):
        return ()
    options: list[str] = []
    seen: set[str] = set()
    for item in value:
        option = normalize_ws(item)
        if not option:
            continue
        marker = norm(option)
        if marker in seen:
            continue
        seen.add(marker)
        options.append(option[:80])
        if len(options) >= 10:
            break
    return tuple(options)


def label_from_key(key: str) -> str:
    raw = re.sub(r"^ai_", "", normalize_ws(key))
    raw = re.sub(r"[_-]+", " ", raw).strip()
    return sentence_case(raw or key)


def infer_field_type(key: str, label: str = "", value: str = "") -> str:
    haystack = norm(f"{key} {label}")
    clean_value = normalize_ws(value)
    if re.fullmatch(r"\d{4}-\d{2}-\d{2}", clean_value) or any(token in haystack for token in ["date", "jour", "echeance", "deadline"]):
        return "date"
    if re.fullmatch(r"\d+(?:[.,]\d+)?", clean_value) or any(token in haystack for token in ["montant", "prix", "cout", "quantite", "nombre", "total"]):
        return "number"
    if any(token in haystack for token in LONG_TEXT_HINTS) or len(clean_value) > 100:
        return "textarea"
    if "type" in haystack or "nature" in haystack or "categorie" in haystack:
        return "select" if clean_value and len(clean_value) <= 60 else "text"
    return "text"


def is_long_text_key(key: str, label: str = "") -> bool:
    haystack = norm(f"{key} {label}")
    return any(token in haystack for token in LONG_TEXT_HINTS)


def is_generated_field_source(value: Any) -> bool:
    source = re.sub(r"[^a-z0-9]+", "-", normalize_ws(value).lower()).strip("-")
    if not source or source in {"manual", "seed"}:
        return False
    return source in {"generated", "learned"} or source.startswith("llm") or source.startswith("local-ml") or "fallback" in source


def sample_prompt(record: dict[str, Any]) -> str:
    general = record.get("general") if isinstance(record.get("general"), dict) else {}
    parts = [
        record.get("rawPrompt"),
        record.get("prompt"),
        record.get("text"),
        general.get("titre"),
        general.get("description"),
        record.get("titre"),
        record.get("description"),
    ]
    return normalize_ws(" ".join(str(part or "") for part in parts))


def sample_details(record: dict[str, Any]) -> dict[str, str]:
    details = record.get("details") if isinstance(record.get("details"), dict) else {}
    cleaned: dict[str, str] = {}
    for key, value in details.items():
        field_key = normalize_ws(key)
        if not field_key or field_key in BASE_DETAIL_KEYS or field_key.startswith("_ai_") or field_key.startswith("__ai_"):
            continue
        if isinstance(value, (str, int, float, bool)):
            text = normalize_ws(value)
            if text:
                cleaned[field_key] = text
    return cleaned


def _field_from_payload(item: dict[str, Any], details: dict[str, str], manual: bool) -> FieldSpec | None:
    key = normalize_ws(item.get("key"))
    if not key or key in BASE_DETAIL_KEYS or key.startswith("_"):
        return None
    if manual and is_generated_field_source(item.get("source")):
        return None
    label = normalize_ws(item.get("label")) or label_from_key(key)
    value = normalize_ws(details.get(key, ""))
    field_type = norm(item.get("type") or infer_field_type(key, label, value))
    if field_type not in {"text", "textarea", "select", "number", "date"}:
        field_type = infer_field_type(key, label, value)
    if field_type == "select" and not normalize_options(item.get("options")):
        field_type = "text" if not normalize_ws(item.get("type")) else "select"
    return FieldSpec(
        key=key,
        label=label,
        type=field_type,
        required=to_bool(item.get("required")),
        value=value,
        options=normalize_options(item.get("options")),
        source="manual" if manual else (normalize_ws(item.get("source")) or "learned"),
    )


def sample_fields(record: dict[str, Any], details: dict[str, str] | None = None) -> tuple[FieldSpec, ...]:
    clean_details = details if details is not None else sample_details(record)
    raw_plan = record.get("fieldPlan") if isinstance(record.get("fieldPlan"), dict) else {}
    plan_add = raw_plan.get("add") if isinstance(raw_plan.get("add"), list) else []
    manual = to_bool(record.get("manual")) or to_bool(record.get("_ai_manual_fields"))

    fields: list[FieldSpec] = []
    seen: set[str] = set()
    for item in plan_add:
        if not isinstance(item, dict):
            continue
        field = _field_from_payload(item, clean_details, manual)
        if not field or field.key in seen:
            continue
        seen.add(field.key)
        fields.append(field)

    if fields:
        return tuple(fields)

    for key, value in clean_details.items():
        if not key.startswith("ai_"):
            continue
        label = label_from_key(key)
        fields.append(
            FieldSpec(
                key=key,
                label=label,
                type=infer_field_type(key, label, value),
                required=False,
                value=value,
                options=(),
                source="manual" if manual else "learned",
            )
        )

    return tuple(fields)


def normalize_training_record(record: dict[str, Any]) -> TrainingSample | None:
    if not isinstance(record, dict):
        return None

    prompt = sample_prompt(record)
    details = sample_details(record)
    fields = sample_fields(record, details)
    general = record.get("general") if isinstance(record.get("general"), dict) else {}
    field_plan = record.get("fieldPlan") if isinstance(record.get("fieldPlan"), dict) else {}
    generated_snapshot = record.get("generatedSnapshot") if isinstance(record.get("generatedSnapshot"), dict) else {}

    if not prompt and not details and not fields:
        return None

    return TrainingSample(
        prompt=prompt,
        general=general,
        details=details,
        field_plan=field_plan,
        fields=fields,
        confirmed=to_bool(record.get("confirmed", True)),
        manual=to_bool(record.get("manual")) or to_bool(record.get("_ai_manual_fields")),
        created_at=parse_timestamp(record.get("createdAt")),
        learning_source=normalize_ws(record.get("_learningSource")),
        generated_snapshot=generated_snapshot,
    )


def normalize_training_records(records: Iterable[dict[str, Any]]) -> list[TrainingSample]:
    samples: list[TrainingSample] = []
    seen: set[str] = set()
    for record in records or []:
        sample = normalize_training_record(record)
        if not sample:
            continue
        signature = json.dumps([sample.prompt, sample.details, [field.key for field in sample.fields]], sort_keys=True, ensure_ascii=False)
        if signature in seen:
            continue
        seen.add(signature)
        samples.append(sample)
    return samples


def field_family(field: FieldSpec) -> str:
    role_tokens = [
        token
        for token in tokenize(f"{field.key} {field.label}", include_bigrams=False)
        if token not in {"ai", "custom", "champ", "demande", "souhaite", "souhaitee", "type", "autre"}
    ]
    return "_".join(role_tokens[:5]) or norm(field.key)


def suppression_signatures(field: FieldSpec) -> set[str]:
    return {
        f"key:{norm(field.key)}",
        f"family:{field_family(field)}",
    }


def field_from_payload(payload: dict[str, Any], fallback_key: str = "") -> FieldSpec | None:
    key = normalize_ws(payload.get("key") or fallback_key)
    if not key:
        return None
    label = normalize_ws(payload.get("label")) or label_from_key(key)
    value = normalize_ws(payload.get("value"))
    field_type = norm(payload.get("type") or infer_field_type(key, label, value))
    if field_type not in {"text", "textarea", "select", "number", "date"}:
        field_type = infer_field_type(key, label, value)
    return FieldSpec(
        key=key,
        label=label,
        type=field_type,
        required=to_bool(payload.get("required")),
        value=value,
        options=normalize_options(payload.get("options")),
        source=normalize_ws(payload.get("source")) or "generated",
    )


def extract_suppression_signals(records: Iterable[dict[str, Any]]) -> set[str]:
    signals: set[str] = set()
    for record in records or []:
        if not isinstance(record, dict):
            continue

        explicit = record.get("suppressedFields")
        if isinstance(explicit, list):
            for item in explicit:
                if isinstance(item, dict):
                    field = field_from_payload(item)
                    if field:
                        signals.update(suppression_signatures(field))

        final_details = sample_details(record)
        final_plan = record.get("fieldPlan") if isinstance(record.get("fieldPlan"), dict) else {}
        final_add = final_plan.get("add") if isinstance(final_plan.get("add"), list) else []
        kept_keys = {
            normalize_ws(item.get("key"))
            for item in final_add
            if isinstance(item, dict) and normalize_ws(item.get("key"))
        } | set(final_details.keys())

        snapshot = record.get("generatedSnapshot") if isinstance(record.get("generatedSnapshot"), dict) else {}
        snapshot_plan = snapshot.get("dynamicFieldPlan") if isinstance(snapshot.get("dynamicFieldPlan"), dict) else {}
        generated_fields = snapshot_plan.get("add") if isinstance(snapshot_plan.get("add"), list) else []
        generated_details = snapshot.get("suggestedDetails") if isinstance(snapshot.get("suggestedDetails"), dict) else {}

        for item in generated_fields:
            if not isinstance(item, dict):
                continue
            field = field_from_payload(item)
            if not field or field.key in BASE_DETAIL_KEYS:
                continue
            generated_value = normalize_ws(generated_details.get(field.key) or field.value)
            if field.key not in kept_keys:
                signals.update(suppression_signatures(field))
                continue
            if generated_value and field.key not in final_details:
                signals.update(suppression_signatures(field))

    return signals


def build_learning_profile(records: Iterable[dict[str, Any]] | Iterable[TrainingSample]) -> LearningProfile:
    raw_items = list(records or [])
    if raw_items and isinstance(raw_items[0], TrainingSample):
        samples = [item for item in raw_items if isinstance(item, TrainingSample)]
        suppression_records: list[dict[str, Any]] = []
    else:
        typed_records = [item for item in raw_items if isinstance(item, dict)]
        samples = normalize_training_records(typed_records)
        suppression_records = typed_records

    field_frequency: Counter[str] = Counter()
    field_cooccurrence: dict[str, Counter[str]] = defaultdict(Counter)
    value_patterns: dict[str, Counter[str]] = defaultdict(Counter)
    evidence_terms: dict[str, Counter[str]] = defaultdict(Counter)

    for sample in samples:
        keys = [field.key for field in sample.fields if field.key]
        for key in keys:
            field_frequency[key] += 1
        for left in keys:
            for right in keys:
                if left != right:
                    field_cooccurrence[left][right] += 1

        prompt_tokens = set(tokenize(sample.prompt, include_bigrams=False))
        for field in sample.fields:
            if not field.value or is_long_text_key(field.key, field.label):
                continue
            value_norm = norm(field.value)
            if not value_norm or len(value_norm) > 80:
                continue
            value_patterns[field.key][value_norm] += 1
            for token in prompt_tokens:
                if len(token) >= 3 and token not in STOPWORDS:
                    evidence_terms[field.key][token] += 1

    return LearningProfile(
        samples=tuple(samples),
        field_frequency=field_frequency,
        field_cooccurrence={key: Counter(value) for key, value in field_cooccurrence.items()},
        value_patterns={key: Counter(value) for key, value in value_patterns.items()},
        evidence_terms={key: Counter(value) for key, value in evidence_terms.items()},
        suppressed_signatures=extract_suppression_signals(suppression_records),
    )


def should_suppress_field(field: FieldSpec, profile: LearningProfile, prompt: str) -> bool:
    if not profile.suppressed_signatures:
        return False
    field_text = f"{field.key} {field.label}"
    # Explicit field mentions are ground truth in the current prompt.
    explicit_tokens = [
        token
        for token in tokenize(field_text, include_bigrams=False)
        if token not in {"ai", "custom", "champ", "demande", "souhaite", "souhaitee", "type", "autre"}
    ]
    if any(re.search(rf"\b{re.escape(token)}\b", norm(prompt)) for token in explicit_tokens):
        return False
    return bool(suppression_signatures(field) & profile.suppressed_signatures)


def append_feedback_jsonl(path: str | Path, record: dict[str, Any]) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    payload = dict(record)
    payload.setdefault("createdAt", datetime.now().isoformat())
    with target.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n")
