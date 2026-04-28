from __future__ import annotations

from collections import Counter
from dataclasses import dataclass
from datetime import datetime, timedelta
from difflib import SequenceMatcher
import re
import unicodedata
from typing import Any


BASE_DETAIL_KEYS = {
    "besoinPersonnalise",
    "descriptionBesoin",
    "niveauUrgenceAutre",
    "dateSouhaiteeAutre",
    "pieceOuContexte",
}

STOPWORDS = {
    "a",
    "afin",
    "ai",
    "alors",
    "au",
    "aux",
    "avec",
    "besoin",
    "ce",
    "cette",
    "dans",
    "de",
    "des",
    "du",
    "demande",
    "demander",
    "demanderai",
    "en",
    "est",
    "et",
    "faire",
    "il",
    "j",
    "je",
    "la",
    "le",
    "les",
    "ma",
    "me",
    "mes",
    "mon",
    "nous",
    "pour",
    "que",
    "qui",
    "souhaite",
    "sur",
    "un",
    "une",
    "veux",
    "voudrais",
}

MONTHS = {
    "janvier": 1,
    "fevrier": 2,
    "mars": 3,
    "avril": 4,
    "mai": 5,
    "juin": 6,
    "juillet": 7,
    "aout": 8,
    "septembre": 9,
    "octobre": 10,
    "novembre": 11,
    "decembre": 12,
}


@dataclass
class LearnedField:
    key: str
    label: str
    type: str = "text"
    required: bool = False
    value: str = ""
    options: list[str] | None = None
    source: str = "learned"


@dataclass
class RankedSample:
    score: float
    sample: dict[str, Any]
    fields: list[LearnedField]
    manual: bool
    learning_source: str
    created_at: datetime


def normalize_ws(value: Any) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip()


def norm(value: Any) -> str:
    text = unicodedata.normalize("NFD", str(value or ""))
    text = "".join(ch for ch in text if unicodedata.category(ch) != "Mn")
    text = text.replace("\u2019", "'").replace("`", "'")
    text = re.sub(r"[^a-zA-Z0-9+#/.'-]+", " ", text)
    return normalize_ws(text).lower()


def slug(value: Any, prefix: str = "ai_") -> str:
    raw = norm(value)
    raw = re.sub(r"[^a-z0-9]+", "_", raw).strip("_")
    if not raw:
        return ""
    if prefix and not raw.startswith(prefix):
        raw = prefix + raw
    return raw[:64]


def tokenize(value: Any, include_bigrams: bool = True) -> list[str]:
    words = re.findall(r"[a-z0-9]+(?:/[a-z0-9]+)?", norm(value))
    kept: list[str] = []
    for word in words:
        if len(word) <= 1 or word in STOPWORDS:
            continue
        if len(word) > 4 and word.endswith("s"):
            word = word[:-1]
        kept.append(word)

    if include_bigrams:
        kept.extend(f"{left}_{right}" for left, right in zip(kept, kept[1:]))

    return kept


def sentence_case(value: Any) -> str:
    clean = normalize_ws(value)
    if not clean:
        return ""
    return clean[:1].upper() + clean[1:]


def to_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return int(value) == 1
    return norm(value) in {"1", "true", "yes", "oui", "on"}


def parse_timestamp(value: Any) -> datetime:
    raw = normalize_ws(value)
    if not raw:
        return datetime.min
    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00")).replace(tzinfo=None)
    except Exception:
        return datetime.min


def sample_prompt(sample: dict[str, Any]) -> str:
    general = sample.get("general") if isinstance(sample.get("general"), dict) else {}
    parts = [
        sample.get("rawPrompt"),
        sample.get("prompt"),
        general.get("titre"),
        general.get("description"),
    ]
    return normalize_ws(" ".join(str(part or "") for part in parts))


def sample_details(sample: dict[str, Any]) -> dict[str, str]:
    details = sample.get("details") if isinstance(sample.get("details"), dict) else {}
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


def infer_field_type(key: str, label: str, value: str = "") -> str:
    haystack = norm(f"{key} {label}")
    if re.fullmatch(r"\d{4}-\d{2}-\d{2}", value or "") or any(token in haystack for token in ["date", "jour", "echeance"]):
        return "date"
    if re.fullmatch(r"\d+(?:[.,]\d+)?", value or "") or any(token in haystack for token in ["montant", "prix", "cout", "quantite", "nombre", "total"]):
        return "number"
    if any(token in haystack for token in ["description", "justification", "motif", "raison", "contexte", "usage", "detail"]):
        return "textarea"
    return "text"


def label_from_key(key: str) -> str:
    raw = re.sub(r"^ai_", "", key)
    raw = re.sub(r"[_-]+", " ", raw).strip()
    return sentence_case(raw or key)


def normalize_options(value: Any) -> list[str]:
    if not isinstance(value, list):
        return []
    options: list[str] = []
    seen: set[str] = set()
    for option in value:
        text = normalize_ws(option)
        if not text:
            continue
        ntext = norm(text)
        if ntext in seen:
            continue
        seen.add(ntext)
        options.append(text[:80])
        if len(options) >= 8:
            break
    return options


def field_role(field: LearnedField) -> str:
    haystack = norm(f"{field.key} {field.label}")
    if field.type == "date" or any(token in haystack for token in ["date", "jour", "echeance", "deadline"]):
        return "date"
    if field.type == "number" or any(token in haystack for token in ["montant", "prix", "cout", "quantite", "nombre", "total"]):
        return "number"
    if "email" in haystack or "mail" in haystack:
        return "email"
    if any(token in haystack for token in ["telephone", "tel", "mobile", "numero"]):
        return "phone"
    if any(token in haystack for token in ["specification", "specifique", "modele", "model", "reference", "taille", "dimension", "version"]):
        return "specification"
    if "attestation" in haystack:
        return "attestation"
    if any(token in haystack for token in ["formation", "certification", "cours"]):
        return "training"
    if "salle" in haystack:
        return "room"
    if any(token in haystack for token in ["organisme", "destinataire", "beneficiaire", "recipient"]):
        return "organization"
    if any(token in haystack for token in ["lieu", "zone", "destination", "depart", "arrivee", "adresse"]):
        return "location"
    if any(token in haystack for token in ["materiel", "equipement", "objet", "article", "produit", "accessoire", "outil"]):
        return "object"
    if any(token in haystack for token in ["type", "nature", "categorie", "category", "frais", "shift", "poste", "tour"]):
        return "category"
    if any(token in haystack for token in ["motif", "justification", "raison", "usage", "contexte", "description", "detail"]):
        return "reason"
    if any(token in haystack for token in ["duree", "periode", "semaine", "mois", "journee"]):
        return "period"
    if any(token in haystack for token in ["horaire", "heure", "creneau", "debut", "fin"]):
        return "time"
    return "generic"


def sample_fields(sample: dict[str, Any]) -> list[LearnedField]:
    details = sample_details(sample)
    raw_plan = sample.get("fieldPlan") if isinstance(sample.get("fieldPlan"), dict) else {}
    plan_add = raw_plan.get("add") if isinstance(raw_plan.get("add"), list) else []
    fields: list[LearnedField] = []
    seen: set[str] = set()

    for item in plan_add:
        if not isinstance(item, dict):
            continue
        key = normalize_ws(item.get("key"))
        if not key or key in BASE_DETAIL_KEYS or key.startswith("_"):
            continue
        if key in seen:
            continue
        seen.add(key)
        value = details.get(key, normalize_ws(item.get("value")))
        label = normalize_ws(item.get("label")) or label_from_key(key)
        field_type = norm(item.get("type") or infer_field_type(key, label, value))
        if field_type not in {"text", "textarea", "select", "number", "date"}:
            field_type = infer_field_type(key, label, value)
        fields.append(
            LearnedField(
                key=key,
                label=label,
                type=field_type,
                required=to_bool(item.get("required")),
                value=value,
                options=normalize_options(item.get("options")),
                source="manual" if to_bool(sample.get("manual")) else "learned",
            )
        )

    if fields:
        return fields

    for key, value in details.items():
        if not key.startswith("ai_"):
            continue
        label = label_from_key(key)
        fields.append(
            LearnedField(
                key=key,
                label=label,
                type=infer_field_type(key, label, value),
                required=False,
                value=value,
                options=[],
                source="manual" if to_bool(sample.get("manual")) else "learned",
            )
        )

    return fields


def sample_schema_text(fields: list[LearnedField]) -> str:
    return " ".join(f"{field.key} {field.label}" for field in fields)


def structure_similarity(left: str, right: str) -> float:
    markers = [
        "je veux",
        "je souhaite",
        "besoin de",
        "demande de",
        "reservation",
        "pour",
        "car",
        "vers",
        "le ",
        "la ",
        "un ",
        "une ",
    ]
    left_norm = norm(left)
    right_norm = norm(right)
    left_markers = {marker for marker in markers if marker in left_norm}
    right_markers = {marker for marker in markers if marker in right_norm}
    if not left_markers or not right_markers:
        return 0.0
    return len(left_markers & right_markers) / len(left_markers | right_markers)


def text_similarity(source: str, sample: dict[str, Any], fields: list[LearnedField]) -> float:
    query_tokens = set(tokenize(source))
    prompt = sample_prompt(sample)
    prompt_tokens = set(tokenize(prompt))

    if not query_tokens or not prompt_tokens:
        return 0.0

    overlap = query_tokens & prompt_tokens
    union = query_tokens | prompt_tokens
    jaccard = len(overlap) / max(1, len(union))
    query_coverage = len(overlap) / max(1, len(query_tokens))
    sample_coverage = len(overlap) / max(1, len(prompt_tokens))
    sequence = SequenceMatcher(None, norm(source), norm(prompt)).ratio()

    schema_tokens = set(tokenize(sample_schema_text(fields), include_bigrams=False))
    schema_bridge = len(query_tokens & schema_tokens) / max(1, min(len(query_tokens), len(schema_tokens))) if schema_tokens else 0.0

    score = (
        jaccard * 0.34
        + query_coverage * 0.28
        + sample_coverage * 0.12
        + sequence * 0.18
        + structure_similarity(source, prompt) * 0.06
        + schema_bridge * 0.02
    )

    if norm(source) == norm(prompt):
        score = max(score, 0.98)

    return max(0.0, min(1.0, score))


def rank_feedback(source: str, samples: list[dict[str, Any]]) -> list[RankedSample]:
    ranked: list[RankedSample] = []
    timestamps = [parse_timestamp(sample.get("createdAt")) for sample in samples if isinstance(sample, dict)]
    valid_ts = [ts for ts in timestamps if ts != datetime.min]
    newest = max(valid_ts) if valid_ts else datetime.min
    oldest = min(valid_ts) if valid_ts else datetime.min

    for sample in samples:
        if not isinstance(sample, dict):
            continue
        fields = sample_fields(sample)
        if not fields:
            continue
        base_score = text_similarity(source, sample, fields)
        if base_score < 0.22:
            continue

        created_at = parse_timestamp(sample.get("createdAt"))
        recency = 0.0
        if created_at != datetime.min and newest > oldest:
            recency = max(0.0, min(1.0, (created_at - oldest).total_seconds() / (newest - oldest).total_seconds())) * 0.04

        manual = to_bool(sample.get("manual")) or to_bool(sample.get("_ai_manual_fields"))
        source_boost = 0.07 if manual else 0.0
        if normalize_ws(sample.get("_learningSource")) == "database":
            source_boost += 0.03

        ranked.append(
            RankedSample(
                score=min(1.0, base_score + source_boost + recency),
                sample=sample,
                fields=fields,
                manual=manual,
                learning_source=normalize_ws(sample.get("_learningSource")),
                created_at=created_at,
            )
        )

    ranked.sort(key=lambda item: (item.score, item.manual, item.created_at), reverse=True)
    return ranked[:8]


def first_date(source: str) -> str:
    clean = norm(source)
    today = datetime.now().date()

    relative_patterns = [
        (r"\bdemain\b", 1),
        (r"\bapres demain\b", 2),
        (r"\baujourd hui\b", 0),
    ]
    for pattern, delta in relative_patterns:
        if re.search(pattern, clean):
            return (today + timedelta(days=delta)).isoformat()

    match = re.search(r"\b(\d{4})-(\d{1,2})-(\d{1,2})\b", clean)
    if match:
        year, month, day = map(int, match.groups())
        return safe_date(year, month, day)

    match = re.search(r"\b(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?\b", clean)
    if match:
        day = int(match.group(1))
        month = int(match.group(2))
        year = int(match.group(3) or today.year)
        if year < 100:
            year += 2000
        return safe_date(year, month, day)

    month_pattern = "|".join(re.escape(name) for name in MONTHS)
    match = re.search(rf"\b(\d{{1,2}})(?:er)?\s+({month_pattern})(?:\s+(\d{{4}}))?\b", clean)
    if match:
        day = int(match.group(1))
        month = MONTHS.get(match.group(2), today.month)
        year = int(match.group(3) or today.year)
        parsed = safe_date(year, month, day)
        if parsed and match.group(3) is None:
            parsed_date = datetime.fromisoformat(parsed).date()
            if parsed_date < today:
                parsed = safe_date(year + 1, month, day)
        return parsed

    return ""


def safe_date(year: int, month: int, day: int) -> str:
    try:
        return datetime(year, month, day).date().isoformat()
    except ValueError:
        return ""


def first_number(source: str) -> str:
    clean = norm(source)
    match = re.search(r"\b(\d+(?:[.,]\d+)?)\s*(?:tnd|dt|dinar|dinars|eur|euro|euros)\b", clean)
    if match:
        return match.group(1).replace(",", ".")
    match = re.search(r"\b(\d+(?:[.,]\d+)?)\b", clean)
    return match.group(1).replace(",", ".") if match else ""


def normalize_hour(hour: str, minute: str = "") -> str:
    try:
        hour_number = max(0, min(23, int(hour)))
    except ValueError:
        return ""
    minute_digits = re.sub(r"\D+", "", minute or "")
    if not minute_digits or minute_digits == "00":
        return f"{hour_number}h"
    return f"{hour_number}h{minute_digits[:2].zfill(2)}"


def extract_time_range(source: str) -> tuple[str, str, str]:
    clean = normalize_ws(source)
    patterns = [
        r"\b(?:de\s+)?([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?\s*(?:-|a|to|jusqu(?:'|e)?a)\s*([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?\b",
        r"\b(?:de\s+)?([01]?\d|2[0-3])\s+heures?\s*(?:([0-5]\d)\s*)?(?:-|a|to|jusqu(?:'|e)?a)\s*([01]?\d|2[0-3])\s+heures?(?:\s*([0-5]\d))?\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, norm(clean), flags=re.IGNORECASE)
        if not match:
            continue
        start = normalize_hour(match.group(1), match.group(2) or "")
        end = normalize_hour(match.group(3), match.group(4) or "")
        if start and end:
            return start, end, f"{start}-{end}"

    match = re.search(r"\b([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?\b", norm(clean), flags=re.IGNORECASE)
    if match:
        single = normalize_hour(match.group(1), match.group(2) or "")
        return single, "", single

    return "", "", ""


def extract_period(source: str) -> str:
    clean = norm(source)
    period_patterns = [
        (r"\bsemaine prochaine\b", "Semaine prochaine"),
        (r"\bsemaine en cours\b", "Semaine en cours"),
        (r"\bmois prochain\b", "Mois prochain"),
        (r"\bmois en cours\b", "Mois en cours"),
        (r"\baujourd hui\b", "Aujourd'hui"),
        (r"\bdemain\b", "Demain"),
        (r"\bapres demain\b", "Apres-demain"),
    ]
    for pattern, label in period_patterns:
        if re.search(pattern, clean):
            return label

    match = re.search(r"\b(?:pendant|pour|durant|sur)\s+(\d+|un|une)\s+(jour|jours|semaine|semaines|mois)\b", clean)
    if match:
        amount = "1" if match.group(1) in {"un", "une"} else match.group(1)
        return f"{amount} {match.group(2)}"

    return ""


def extract_specification(source: str) -> str:
    clean = normalize_ws(source)
    patterns = [
        r"\b(\d+(?:[.,]\d+)?\s*(?:pouces?|inch|inches|cm|mm|go|gb|to|tb|hz|mah|w|watts?))\b",
        r"\b(?:modele|model|reference|ref)\s*[:\-]?\s*([A-Za-z0-9][A-Za-z0-9_\-/\. ]{1,50})",
        r"\b([A-Za-z0-9]+(?:[-_/\.][A-Za-z0-9]+)+)\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, clean, flags=re.IGNORECASE)
        if match:
            return normalize_ws(match.group(1)).strip(" ,;:-")[:80]
    return ""


def clause_after(source: str, keywords: list[str], max_len: int = 140) -> str:
    clean = normalize_ws(source)
    for keyword in keywords:
        pattern = rf"\b{re.escape(keyword)}\b\s*(?:de|d'|du|des|:|-)?\s*(.{{2,{max_len}}}?)(?=\.|,|;|$)"
        match = re.search(pattern, clean, flags=re.IGNORECASE)
        if not match:
            continue
        value = normalize_ws(match.group(1)).strip(" ,;:-")
        value = re.sub(r"^(?:de|du|des|d'|un|une|le|la|les)\s+", "", value, flags=re.IGNORECASE)
        value = re.split(r"\b(?:pour|car|parce que|afin de|avec|le|la|les|du|des|a partir)\b", value, maxsplit=1, flags=re.IGNORECASE)[0]
        value = normalize_ws(value).strip(" ,;:-")
        if value:
            return value[:max_len]
    return ""


def extract_reason(source: str) -> str:
    clean = normalize_ws(source)
    patterns = [
        r"\b(?:pour|afin de|car|parce que)\s+(.{2,180}?)(?=\.|,|;|$)",
        r"\b(?:motif|raison|justification|usage)\s*(?:est|:|-)?\s+(.{2,180}?)(?=\.|,|;|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, clean, flags=re.IGNORECASE)
        if match:
            value = normalize_ws(match.group(1)).strip(" ,;:-")
            if value:
                return value[:180]
    return clean[:180]


def extract_requested_object(source: str) -> str:
    clean = normalize_ws(source)
    patterns = [
        r"\b(?:je\s+veux|je\s+souhaite|j'ai\s+besoin\s+de|besoin\s+de|demande\s+de|demande\s+d'|obtenir|avoir)\s+(.{2,120}?)(?=\s+(?:pour|afin|car|parce que|avec|qui|le|la|les|a partir|urgent)\b|[.,;:]|$)",
        r"\b(?:un|une|des)\s+(.{2,80}?)(?=\s+(?:pour|afin|car|parce que|avec|qui|le|la|les|a partir|urgent)\b|[.,;:]|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, clean, flags=re.IGNORECASE)
        if not match:
            continue
        value = normalize_ws(match.group(1))
        value = re.sub(r"^(?:un|une|du|de|des|d'|le|la|les)\s+", "", value, flags=re.IGNORECASE)
        value = re.sub(r"\b(?:professionnel|professionnelle|nouveau|nouvelle)\b", "", value, flags=re.IGNORECASE)
        value = normalize_ws(value).strip(" ,;:-")
        if value and norm(value) not in {"demande", "besoin"}:
            return value[:90]
    return ""


def extract_attestation(source: str) -> str:
    match = re.search(r"\b(attestation(?:\s+de|\s+d')?\s+[\w' -]{2,60})", source, flags=re.IGNORECASE)
    if match:
        return normalize_ws(match.group(1)).strip(" ,;:-")[:90]
    return ""


def extract_training_name(source: str) -> str:
    patterns = [
        r"\bformation\s+(?:de|d'|en|sur)\s+(.{2,80}?)(?=\s+(?:pour|le|la|qui|avec|a|\u00e0)\b|[.,;:]|$)",
        r"\bcours\s+(?:de|d'|en|sur)\s+(.{2,80}?)(?=\s+(?:pour|le|la|qui|avec|a|\u00e0)\b|[.,;:]|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if match:
            return sentence_case(normalize_ws(match.group(1)).strip(" ,;:-")[:90])
    return ""


def extract_location(source: str, key_text: str) -> str:
    clean = normalize_ws(source)
    lowered_key = norm(key_text)
    if any(token in lowered_key for token in ["depart", "origine"]):
        match = re.search(r"\b(?:de|depuis|depart\s+de)\s+([\w' -]{2,70}?)(?=\s+(?:vers|a|\u00e0|pour)\b|[.,;:]|$)", clean, flags=re.IGNORECASE)
        if match:
            return normalize_ws(match.group(1)).strip(" ,;:-")
    if any(token in lowered_key for token in ["destination", "arrivee", "souhaite"]):
        match = re.search(r"\b(?:vers|a|\u00e0|destination\s+de?)\s+([\w' -]{2,70}?)(?=\s+(?:pour|le|la|qui|avec)\b|[.,;:]|$)", clean, flags=re.IGNORECASE)
        if match:
            return normalize_ws(match.group(1)).strip(" ,;:-")
    match = re.search(r"\b(?:a|\u00e0|au|aux|dans|salle)\s+([\w' -]{2,70}?)(?=\s+(?:pour|le|la|qui|avec|debut|d\u00e9bute)\b|[.,;:]|$)", clean, flags=re.IGNORECASE)
    return normalize_ws(match.group(1)).strip(" ,;:-") if match else ""


def extract_named_value(source: str, field: LearnedField) -> str:
    labels = [field.label, re.sub(r"^ai_", "", field.key).replace("_", " ")]
    clean = normalize_ws(source)
    for label in labels:
        label_norm = normalize_ws(label)
        if not label_norm:
            continue
        pattern = rf"\b{re.escape(label_norm)}\b\s*(?:est|:|-)?\s+(.{{2,100}}?)(?=\s+(?:pour|car|avec|le|la|les|du|des)\b|[.,;:]|$)"
        match = re.search(pattern, clean, flags=re.IGNORECASE)
        if match:
            return normalize_ws(match.group(1)).strip(" ,;:-")[:100]
    return ""


def token_spans(text: str) -> list[dict[str, Any]]:
    tokens: list[dict[str, Any]] = []
    for match in re.finditer(r"[\w+#/.'-]+", text, flags=re.UNICODE):
        token_norm = norm(match.group(0))
        if not token_norm:
            continue
        tokens.append({
            "norm": token_norm,
            "raw": match.group(0),
            "start": match.start(),
            "end": match.end(),
        })
    return tokens


def find_token_sequences(haystack: list[str], needle: list[str]) -> list[int]:
    if not haystack or not needle or len(needle) > len(haystack):
        return []
    matches: list[int] = []
    width = len(needle)
    for index in range(0, len(haystack) - width + 1):
        if haystack[index:index + width] == needle:
            matches.append(index)
    return matches


def meaningful_context(tokens: list[dict[str, Any]], start: int, end: int) -> tuple[list[str], list[str]]:
    left: list[str] = []
    for token in reversed(tokens[:start]):
        value = token["norm"]
        if value in STOPWORDS:
            continue
        left.append(value)
        if len(left) >= 4:
            break
    left.reverse()

    right: list[str] = []
    for token in tokens[end:]:
        value = token["norm"]
        if value in STOPWORDS:
            continue
        right.append(value)
        if len(right) >= 4:
            break

    return left, right


def role_boundary_tokens(role: str) -> set[str]:
    if role in {"reason", "generic", "time", "period"}:
        return {"date", "urgent", "urgence"}
    if role in {"location", "room", "organization"}:
        return {"pour", "car", "parce", "afin", "avec", "qui", "que", "le", "la", "les", "debut", "debute"}
    if role == "category":
        return {"pour", "car", "parce", "afin", "avec", "qui", "que", "le", "la", "les", "urgent", "urgence"}
    return {"pour", "car", "parce", "afin", "avec", "qui", "que", "le", "la", "les", "depuis", "vers", "urgent", "urgence"}


def has_request_intro(value: str) -> bool:
    clean = norm(value)
    if not clean:
        return False
    patterns = [
        r"^(?:je|j)\s+(?:veux|souhaite|voudrais|demande|cherche)\b",
        r"^j'ai\s+besoin\b",
        r"^(?:j|je)\s+ai\s+besoin\b",
        r"^besoin\s+(?:de|d|pour)\b",
        r"^demande\s+(?:de|d|pour)\b",
        r"^il\s+me\s+faut\b",
        r"^merci\s+de\b",
    ]
    return any(re.search(pattern, clean) for pattern in patterns)


def strip_candidate_noise(value: str, role: str) -> str:
    clean = normalize_ws(value).strip(" ,;:-")
    if not clean:
        return ""

    clean = re.sub(r"^(?:un|une|du|de|des|d'|le|la|les|l'|au|aux)\s+", "", clean, flags=re.IGNORECASE)
    clean = re.sub(r"^(?:est|sont|sera|serait|concerne|concernant)\s+", "", clean, flags=re.IGNORECASE)

    if role in {"object", "category", "specification", "attestation", "training", "room", "organization", "location"}:
        clean = re.split(
            r"\b(?:pour|car|parce que|afin de|avec|qui|que|urgent|urgence)\b",
            clean,
            maxsplit=1,
            flags=re.IGNORECASE,
        )[0]

    if role == "object":
        clean = re.sub(r"\b(?:professionnel|professionnelle|nouveau|nouvelle|besoin|demande)\b", "", clean, flags=re.IGNORECASE)

    clean = normalize_ws(clean).strip(" ,;:-")
    if not clean:
        return ""

    if role in {"object", "category", "specification", "attestation", "training", "room", "organization", "location", "generic"}:
        if has_request_intro(clean):
            return ""

    rejected = {"demande", "besoin", "autre", "champ", "valeur", "information", "infos"}
    if norm(clean) in rejected:
        return ""

    return clean[:220 if role in {"reason", "generic"} else 100]


def raw_span_from_tokens(source: str, tokens: list[dict[str, Any]], start: int, end: int) -> str:
    start = max(0, start)
    end = min(len(tokens), end)
    if start >= end:
        return ""
    return source[int(tokens[start]["start"]):int(tokens[end - 1]["end"])]


def next_boundary_index(tokens: list[dict[str, Any]], start: int, role: str, max_tokens: int = 10) -> int:
    boundaries = role_boundary_tokens(role)
    end_limit = min(len(tokens), start + max_tokens)
    for index in range(start, end_limit):
        value = tokens[index]["norm"]
        if value in boundaries:
            return index
        if role in {"object", "category", "room", "organization"} and re.fullmatch(r"\d+(?:[.,]\d+)?", value):
            return index
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
            return index
    return end_limit


def extract_between_anchors(source: str, left: list[str], right: list[str], role: str) -> str:
    source_tokens = token_spans(source)
    source_norms = [token["norm"] for token in source_tokens]
    if not source_tokens:
        return ""

    for size in range(min(4, len(left)), 0, -1):
        anchor = left[-size:]
        for match_start in reversed(find_token_sequences(source_norms, anchor)):
            start = match_start + size
            end = next_boundary_index(source_tokens, start, role)
            for right_size in range(min(4, len(right)), 0, -1):
                right_anchor = right[:right_size]
                right_matches = [idx for idx in find_token_sequences(source_norms[start:], right_anchor) if idx > 0]
                if right_matches:
                    end = min(end, start + right_matches[0])
                    break
            candidate = strip_candidate_noise(raw_span_from_tokens(source, source_tokens, start, end), role)
            if candidate:
                return candidate

    for size in range(min(4, len(right)), 0, -1):
        anchor = right[:size]
        for match_start in find_token_sequences(source_norms, anchor):
            end = match_start
            start = max(0, end - 8)
            for index in range(end - 1, -1, -1):
                if source_norms[index] in role_boundary_tokens(role):
                    start = index + 1
                    break
            candidate = strip_candidate_noise(raw_span_from_tokens(source, source_tokens, start, end), role)
            if candidate:
                return candidate

    return ""


def extract_from_learned_examples(source: str, field: LearnedField, examples: list[dict[str, Any]]) -> str:
    role = field_role(field)
    ranked_examples = sorted(examples, key=lambda item: float(item.get("score", 0.0)), reverse=True)
    for example in ranked_examples:
        example_field = example.get("field")
        prompt = normalize_ws(example.get("prompt"))
        if not isinstance(example_field, LearnedField) or not prompt:
            continue

        value = normalize_ws(example_field.value)
        if not value:
            continue

        prompt_tokens = token_spans(prompt)
        value_tokens = [token["norm"] for token in token_spans(value)]
        if not prompt_tokens or not value_tokens:
            continue

        prompt_norms = [token["norm"] for token in prompt_tokens]
        occurrences = find_token_sequences(prompt_norms, value_tokens)
        for occurrence in occurrences:
            left, right = meaningful_context(prompt_tokens, occurrence, occurrence + len(value_tokens))
            candidate = extract_between_anchors(source, left, right, role)
            if candidate:
                return candidate

    return ""


def examples_text(field: LearnedField, examples: list[dict[str, Any]]) -> str:
    parts = [field.key, field.label, field.value]
    for example in examples:
        example_field = example.get("field")
        if isinstance(example_field, LearnedField):
            parts.extend([example_field.key, example_field.label, example_field.value])
        parts.append(example.get("prompt", ""))
    return normalize_ws(" ".join(str(part or "") for part in parts))


def shift_variant(source: str) -> str:
    clean = norm(source)
    if re.search(r"\b(nuit|nocturne)\b", clean):
        return "Nuit"
    if re.search(r"\b(soir|soiree)\b", clean):
        return "Soir"
    if re.search(r"\b(jour|journee|matin)\b", clean):
        return "Jour"
    return ""


def extract_shift_category(source: str, field: LearnedField, examples: list[dict[str, Any]]) -> str:
    variant = shift_variant(source)
    if not variant:
        return ""

    field_text = norm(f"{field.key} {field.label}")
    learned_text = norm(examples_text(field, examples))
    source_text = norm(source)
    context = f"{source_text} {field_text} {learned_text}"
    has_shift_context = any(token in context for token in ["shift", "poste", "tour"])
    if not has_shift_context:
        return ""

    field_is_shift_choice = any(token in field_text for token in ["shift", "poste", "tour"])
    field_is_request_type = (
        re.search(r"\btype\b.*\bdemande\b", field_text) is not None
        or re.search(r"\bdemande\b.*\btype\b", field_text) is not None
        or any(token in field_text for token in ["nature", "categorie", "category"])
    )

    if field_is_request_type and not field_is_shift_choice:
        return f"Shift de {variant.lower()}"

    return variant


def coerce_select(value: str, options: list[str]) -> str:
    if not value:
        return ""
    normalized_value = norm(value)
    for option in options:
        if norm(option) == normalized_value or norm(option) in normalized_value or normalized_value in norm(option):
            return option
    return ""


def extract_value(source: str, field: LearnedField, examples: list[dict[str, Any]] | None = None) -> str:
    haystack = norm(f"{field.key} {field.label}")
    options = field.options or []
    role = field_role(field)

    if field.type == "select":
        for option in options:
            option_norm = norm(option)
            if option_norm and option_norm in norm(source):
                return option
        learned = extract_from_learned_examples(source, field, examples or [])
        return coerce_select(learned, options)

    if role == "date":
        return first_date(source)

    if role == "number":
        return first_number(source)

    if role == "email":
        match = re.search(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}", source)
        return match.group(0) if match else ""

    if role == "phone":
        match = re.search(r"\b(?:\+?\d[\d .-]{6,}\d)\b", source)
        return normalize_ws(match.group(0)) if match else ""

    if role == "specification":
        return extract_specification(source)

    if role == "attestation":
        return extract_attestation(source)

    if role == "training":
        value = extract_training_name(source)
        if value:
            return value

    if role == "room":
        value = clause_after(source, ["salle"], 80)
        if value:
            return sentence_case(value)

    if role == "organization":
        value = clause_after(source, ["pour", "destinataire", "organisme", "chez"], 80)
        if value:
            return value

    if role == "location":
        return extract_location(source, haystack)

    if role == "object":
        value = extract_requested_object(source)
        if value:
            return value

    if role == "category":
        shift_value = extract_shift_category(source, field, examples or [])
        if shift_value:
            return shift_value
        learned = extract_from_learned_examples(source, field, examples or [])
        if learned:
            return learned
        value = extract_requested_object(source)
        if value:
            return value

    if role == "period":
        period = extract_period(source)
        if period:
            return period
        learned = extract_from_learned_examples(source, field, examples or [])
        if learned:
            return learned

    if role == "time":
        start, end, full_range = extract_time_range(source)
        if any(token in haystack for token in ["debut", "depart", "commencement"]):
            return start
        if any(token in haystack for token in ["fin", "sortie"]):
            return end or full_range
        return full_range

    if role == "reason":
        return extract_reason(source)

    named = extract_named_value(source, field)
    if named:
        return named

    learned = extract_from_learned_examples(source, field, examples or [])
    if learned:
        return learned

    if role == "generic" and field.required:
        return strip_candidate_noise(source, role)

    return ""


def prompt_sensitive(field: LearnedField) -> bool:
    return field_role(field) != "generic"


def select_learned_fields(source: str, ranked: list[RankedSample], max_fields: int = 8) -> tuple[list[LearnedField], float, bool]:
    if not ranked:
        return [], 0.0, False

    scores: dict[str, dict[str, Any]] = {}
    order: dict[str, tuple[int, int]] = {}
    strongest_manual = False

    for sample_index, ranked_sample in enumerate(ranked):
        if sample_index == 0 and ranked_sample.manual:
            strongest_manual = True
        for field_index, field in enumerate(ranked_sample.fields):
            key = normalize_ws(field.key)
            if not key or key in BASE_DETAIL_KEYS:
                continue
            weight = ranked_sample.score
            if ranked_sample.manual:
                weight *= 1.55
            if ranked_sample.learning_source == "database":
                weight *= 1.08

            if key not in scores:
                scores[key] = {
                    "weight": 0.0,
                    "field": field,
                    "required": 0.0,
                    "values": Counter(),
                    "examples": [],
                }
                order[key] = (sample_index, field_index)

            scores[key]["weight"] += weight
            if field.required:
                scores[key]["required"] += weight
            if field.value:
                scores[key]["values"][field.value] += weight
            scores[key]["examples"].append({
                "field": field,
                "prompt": sample_prompt(ranked_sample.sample),
                "score": ranked_sample.score,
            })

    selected: list[LearnedField] = []
    for key, data in scores.items():
        weight = float(data["weight"])
        source_field: LearnedField = data["field"]
        minimum = 0.28 if source_field.source == "manual" or strongest_manual else 0.36
        if weight < minimum and ranked[0].score < 0.64:
            continue

        value = extract_value(source, source_field, data.get("examples", []))
        if not value and source_field.type == "select" and source_field.value:
            value = coerce_select(source_field.value, source_field.options or [])

        if not value and prompt_sensitive(source_field) and not source_field.required:
            continue

        selected.append(
            LearnedField(
                key=source_field.key,
                label=source_field.label,
                type=source_field.type,
                required=source_field.required or float(data["required"]) >= max(0.35, weight * 0.45),
                value=value,
                options=source_field.options or [],
                source=source_field.source,
            )
        )

    selected.sort(
        key=lambda field: (
            order.get(field.key, (99, 99))[0],
            order.get(field.key, (99, 99))[1],
            0 if field.source == "manual" else 1,
            0 if field.required else 1,
            field.key,
        )
    )
    return selected[:max_fields], ranked[0].score, strongest_manual


def fallback_fields(source: str) -> list[LearnedField]:
    fields: list[LearnedField] = []
    requested = extract_requested_object(source)
    if requested:
        fields.append(LearnedField("ai_objet_demande", "Objet de la demande", "text", True, requested, [], "generated"))

    spec = extract_specification(source)
    if spec:
        fields.append(LearnedField("ai_specification", "Specification", "text", False, spec, [], "generated"))

    date_value = first_date(source)
    if date_value:
        fields.append(LearnedField("ai_date_souhaitee", "Date souhaitee", "date", False, date_value, [], "generated"))

    number = first_number(source)
    if number and not any(field.value == number for field in fields):
        fields.append(LearnedField("ai_valeur_chiffree", "Valeur chiffree", "number", False, number, [], "generated"))

    reason = extract_reason(source)
    if reason and norm(reason) != norm(requested):
        fields.append(LearnedField("ai_justification", "Justification", "textarea", False, reason, [], "generated"))

    if not fields:
        fields.append(LearnedField("ai_description_libre", "Description libre", "textarea", True, normalize_ws(source)[:220], [], "generated"))

    return fields[:6]


def priority(source: str) -> tuple[str, str]:
    clean = norm(source)
    if re.search(r"\b(urgent|urgence|bloquant|immediat|immediatement|aujourd hui|critique)\b", clean):
        return "HAUTE", "Urgente"
    if re.search(r"\b(pas urgent|quand possible|faible priorite)\b", clean):
        return "BASSE", "Faible"
    return "NORMALE", "Normale"


def build_title(source: str, fields: list[LearnedField]) -> str:
    for field in fields:
        if not field.value:
            continue
        haystack = norm(f"{field.key} {field.label}")
        if any(token in haystack for token in ["materiel", "equipement", "objet", "article", "produit", "accessoire"]):
            return sentence_case(f"Demande de materiel {field.value}")
        if "formation" in haystack:
            return sentence_case(f"Demande de formation {field.value}")
        if "attestation" in haystack:
            return sentence_case(field.value)
        if "salle" in haystack:
            return sentence_case(f"Reservation salle {field.value}")

    requested = extract_requested_object(source)
    if requested:
        return sentence_case(f"Demande de {requested}")

    clean = normalize_ws(source)
    return sentence_case(clean[:90] if clean else "Demande personnalisee")


def confidence_payload(score: float, manual: bool, used_fallback: bool) -> dict[str, Any]:
    if used_fallback:
        return {
            "score": 25,
            "label": "Faible",
            "tone": "info",
            "message": "Aucun historique exploitable: generation libre proposee, confirmation requise.",
        }

    pct = max(35, min(99, int(round(score * 100))))
    if manual:
        pct = max(pct, 86)
        return {
            "score": pct,
            "label": "Elevee",
            "tone": "success",
            "message": "Schema appris depuis des champs saisis manuellement et adapte au texte courant.",
        }

    if pct >= 72:
        label = "Elevee"
        tone = "success"
    elif pct >= 48:
        label = "Moyenne"
        tone = "info"
    else:
        label = "Faible"
        tone = "info"

    return {
        "score": pct,
        "label": label,
        "tone": tone,
        "message": "Correspondance calculee avec des demandes confirmees. Les valeurs proviennent du texte courant.",
    }


def field_to_dict(field: LearnedField) -> dict[str, Any]:
    payload = {
        "key": field.key,
        "label": field.label,
        "type": field.type if field.type in {"text", "textarea", "select", "number", "date"} else "text",
        "required": bool(field.required),
        "value": field.value,
        "source": field.source,
    }
    if field.type == "select":
        payload["options"] = field.options or []
    return payload


def extract_feedback_samples(request_data: dict[str, Any]) -> list[dict[str, Any]]:
    raw = request_data.get("acceptedAutreFeedback")
    if not isinstance(raw, list):
        return []
    return [sample for sample in raw if isinstance(sample, dict)]


def generate_autre_response(request_data: dict[str, Any]) -> dict[str, Any]:
    source = normalize_ws(request_data.get("text") or request_data.get("prompt") or "")
    if not source:
        general = request_data.get("general") if isinstance(request_data.get("general"), dict) else {}
        source = normalize_ws(general.get("aiDescriptionPrompt") or general.get("description") or general.get("titre"))

    ranked = rank_feedback(source, extract_feedback_samples(request_data))
    learned_fields, match_score, manual_schema = select_learned_fields(source, ranked)
    used_fallback = not learned_fields
    fields = learned_fields if learned_fields else fallback_fields(source)

    general_priority, urgency = priority(source)
    title = build_title(source, fields)
    description = normalize_ws(source)
    details = {field.key: field.value for field in fields if field.value}

    return {
        "correctedText": source,
        "general": {
            "titre": title,
            "description": description,
            "priorite": general_priority,
            "categorie": "Autre",
            "typeDemande": "Autre",
        },
        "details": details,
        "remove_fields": ["ALL"],
        "custom_fields": [field_to_dict(field) for field in fields],
        "replace_base": True,
        "dynamicFieldConfidence": confidence_payload(match_score, manual_schema, used_fallback),
        "skipConfirmationRestriction": False,
        "needsLlmFallback": used_fallback,
        "niveauUrgenceAutre": urgency,
    }
