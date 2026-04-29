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

GENERIC_REQUEST_OBJECTS = {
    "deplacement",
    "demande",
    "demande transport",
    "formation",
    "trajet",
    "transport",
    "voyage",
}

LOCATION_NOISE_TERMS = {
    "besoin",
    "demande",
    "formation",
    "moyen",
    "souhaite",
    "trajet",
    "transport",
}

FORMATION_TYPE_MARKERS = {
    "certification",
    "certifiante",
    "externe",
    "interne",
    "professionnel",
    "professionnelle",
}

MIN_USABLE_MATCH_SCORE = 0.62


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


def is_generated_field_source(value: Any) -> bool:
    source = re.sub(r"[^a-z0-9]+", "-", normalize_ws(value).lower()).strip("-")
    if not source or source == "manual":
        return False
    return source in {"generated", "learned"} or source.startswith("llm") or source.startswith("local-ml") or "fallback" in source


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
        if to_bool(sample.get("manual")) and is_generated_field_source(item.get("source")):
            continue
        if key in seen:
            continue
        seen.add(key)
        value = details.get(key, "")
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
                source="manual" if to_bool(sample.get("manual")) else (norm(item.get("source")) or "learned"),
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


def contains_norm_term(clean: str, term: str) -> bool:
    normalized_term = norm(term)
    if not normalized_term:
        return False
    return re.search(rf"\b{re.escape(normalized_term)}\b", clean) is not None


def value_has_prompt_evidence(source: str, value: str) -> bool:
    clean = norm(source)
    value_norm = norm(value)
    if not clean or not value_norm:
        return False

    if contains_norm_term(clean, value_norm):
        return True

    ignored = STOPWORDS | {
        "ai",
        "autre",
        "besoin",
        "champ",
        "custom",
        "demande",
        "details",
        "information",
        "infos",
        "urgent",
        "urgence",
        "valeur",
    }
    tokens = [
        token
        for token in tokenize(value_norm, include_bigrams=False)
        if len(token) >= 3 and token not in ignored
    ]
    if not tokens:
        return False

    if len(tokens) == 1:
        return contains_norm_term(clean, tokens[0])

    return all(contains_norm_term(clean, token) for token in tokens)


def schema_support_similarity(source: str, fields: list[LearnedField]) -> float:
    roles = [field_role(field) for field in fields]
    meaningful_roles = [role for role in roles if role != "generic"]
    if not meaningful_roles:
        return 0.0

    schema_has_specification = "specification" in meaningful_roles
    supported = 0
    for role in meaningful_roles:
        if source_supports_role(source, role, schema_has_specification):
            supported += 1

    return supported / max(1, len(meaningful_roles))


def source_has_reason_evidence(source: str) -> bool:
    clean = norm(source)
    return re.search(
        r"\b(?:motif|raison|raisons|justification|usage|car|parce que|afin de|pour cause|a cause de|suite a)\b"
        r"|\bpour\s+(?:mission|projet|client|travail|intervention|deplacement|reunion|raisons?)\b",
        clean,
    ) is not None


def source_has_organization_evidence(source: str) -> bool:
    clean = norm(source)
    return re.search(
        r"\b(?:destinataire|organisme|beneficiaire|chez|societe|entreprise|client|fournisseur|ambassade|consulat|banque|ecole|universite)\b",
        clean,
    ) is not None


def source_supports_role(source: str, role: str, schema_has_specification: bool = False) -> bool:
    if role == "date":
        return bool(first_date(source))
    if role == "number":
        return bool(first_number(source))
    if role == "email":
        return re.search(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}", source) is not None
    if role == "phone":
        return re.search(r"\b(?:\+?\d[\d .-]{6,}\d)\b", source) is not None
    if role == "time":
        return bool(extract_time_range(source)[2])
    if role == "period":
        return bool(extract_period(source))
    if role == "location":
        start, end = extract_route_locations(source)
        return bool(start or end or clean_location_candidate(source))
    if role == "training":
        return bool(extract_training_name(source))
    if role == "specification":
        return bool(extract_specification(source) or extract_object_qualifier(source))
    if role == "object":
        value = extract_requested_object(source)
        if schema_has_specification:
            value = split_requested_object(source)[0] or value
        return bool(value and not is_generic_request_object(value))
    if role == "category":
        return bool(shift_variant(source))
    if role == "reason":
        return source_has_reason_evidence(source)
    if role == "organization":
        return source_has_organization_evidence(source)
    if role in {"attestation", "room"}:
        return True
    return False


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
    schema_support = schema_support_similarity(source, fields)

    score = (
        jaccard * 0.34
        + query_coverage * 0.28
        + sample_coverage * 0.12
        + sequence * 0.18
        + structure_similarity(source, prompt) * 0.06
        + schema_bridge * 0.02
        + schema_support * 0.42
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


def clean_location_candidate(value: str) -> str:
    clean = normalize_ws(value).strip(" ,;:-")
    if not clean:
        return ""

    clean = re.sub(r"^(?:de|du|des|d'|depuis|a|au|aux|dans|vers)\s+", "", clean, flags=re.IGNORECASE)
    clean = re.split(
        r"\b(?:pour|afin|car|avec|formation|certification|transport|trajet|deplacement|le\s+\d{1,2}|la\s+formation|type\s+de)\b",
        clean,
        maxsplit=1,
        flags=re.IGNORECASE,
    )[0]
    clean = normalize_ws(clean).strip(" ,;:-")
    if not clean:
        return ""

    clean_norm = norm(clean)
    if any(contains_norm_term(clean_norm, token) for token in LOCATION_NOISE_TERMS):
        return ""
    if re.search(r"\d", clean_norm):
        return ""
    if len(clean_norm) < 2:
        return ""

    return clean[:80]


def extract_route_locations(source: str) -> tuple[str, str]:
    clean = normalize_ws(source)

    for vers_match in re.finditer(r"\bvers\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' -]{1,80}?)(?=\s+(?:le|la|les|pour|afin|car|avec|du|de|des|formation|certification)\b|[.,;:]|$)", clean, flags=re.IGNORECASE):
        left_context = clean[:vers_match.start()]
        end = clean_location_candidate(vers_match.group(1))
        if not end:
            continue

        prepositions = list(re.finditer(r"(?:\bde|\bdu|\bdes|\bd'|\bdepuis|\bdepart\s+de)\s+", left_context, flags=re.IGNORECASE))
        for from_match in reversed(prepositions):
            start = clean_location_candidate(left_context[from_match.end():])
            if start:
                return start, end

    patterns = [
        r"\b(?:de|du|des|d'|depuis|depart\s+de)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' -]{1,80}?)\s+vers\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' -]{1,80}?)(?=\s+(?:le|la|les|pour|afin|car|avec|du|de|des|formation|certification)\b|[.,;:]|$)",
        r"\b(?:de|du|des|d'|depuis|depart\s+de)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' -]{1,80}?)\s+(?:a|à|jusqu(?:'|e)?a|jusqu(?:'|e)?à)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ' -]{1,80}?)(?=\s+(?:le|la|les|pour|afin|car|avec|du|de|des|formation|certification)\b|[.,;:]|$)",
    ]

    for pattern in patterns:
        matches = list(re.finditer(pattern, clean, flags=re.IGNORECASE))
        for match in reversed(matches):
            start = clean_location_candidate(match.group(1))
            end = clean_location_candidate(match.group(2))
            if start and end:
                return start, end

    return "", ""


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
        r"\bdemande\s+(.{2,120}?)(?=\s+(?:apres|après|suite|pour|afin|car|parce que|avec|qui|le|la|les|a partir|urgent)\b|[.,;:]|$)",
        r"\b(?:un|une|des)\s+(.{2,80}?)(?=\s+(?:pour|afin|car|parce que|avec|qui|le|la|les|a partir|urgent)\b|[.,;:]|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, clean, flags=re.IGNORECASE)
        if not match:
            continue
        value = normalize_ws(match.group(1))
        value = re.sub(r"^(?:un|une|du|de|des|d'|le|la|les)\s+", "", value, flags=re.IGNORECASE)
        value = re.sub(r"^(?:demande|besoin)\s+(?:de|d'|du|des)\s+", "", value, flags=re.IGNORECASE)
        value = re.sub(r"\b(?:professionnel|professionnelle|nouveau|nouvelle)\b", "", value, flags=re.IGNORECASE)
        value = normalize_ws(value).strip(" ,;:-")
        if value and norm(value) not in {"demande", "besoin"}:
            return value[:90]
    return ""


def is_generic_request_object(value: str) -> bool:
    clean = norm(value)
    clean = re.sub(r"^(?:demande|besoin)\s+(?:de|d|du|des)\s+", "", clean)
    clean = normalize_ws(clean)
    return clean in GENERIC_REQUEST_OBJECTS


def extract_transport_mode(source: str, options: list[str] | None = None) -> str:
    if not options:
        return ""

    clean = norm(source)
    for option in options:
        option_norm = norm(option)
        if option_norm and contains_norm_term(clean, option_norm):
            return option

    return ""


def split_requested_object(source: str) -> tuple[str, str]:
    phrase = extract_requested_object(source)
    if not phrase:
        return "", ""

    explicit_spec = extract_specification(phrase)
    if explicit_spec:
        object_part = re.sub(re.escape(explicit_spec), "", phrase, flags=re.IGNORECASE)
        object_part = strip_candidate_noise(object_part, "object")
        return object_part, explicit_spec

    tokens = token_spans(phrase)
    if len(tokens) <= 1:
        return phrase, ""

    head = strip_candidate_noise(raw_span_from_tokens(phrase, tokens, 0, len(tokens) - 1), "object")
    tail = strip_candidate_noise(raw_span_from_tokens(phrase, tokens, len(tokens) - 1, len(tokens)), "specification")
    if not head or norm(tail) in STOPWORDS:
        return phrase, ""

    return head, tail


def extract_object_qualifier(source: str) -> str:
    explicit = extract_specification(source)
    if explicit:
        return explicit

    return split_requested_object(source)[1]


def extract_attestation(source: str) -> str:
    match = re.search(
        r"\b(attestation(?:\s+de|\s+d')?\s+[\w' -]{2,60}?)(?=\s+(?:pour|afin|car|parce que|avec|qui|que)\b|[.,;:]|$)",
        source,
        flags=re.IGNORECASE,
    )
    if match:
        return normalize_ws(match.group(1)).strip(" ,;:-")[:90]
    return ""


def extract_training_name(source: str) -> str:
    type_marker = r"(?:certification|certifiante|externe|interne|professionnel|professionnelle)"
    patterns = [
        rf"\bformation\s+(?:{type_marker}\s+)?(?:(?:de|d'|en|sur)\s+)?(.{{2,120}}?)(?=\s+(?:cette\s+formation|formation\s+est|type\s+de\s+formation|pour|le|la|qui|avec|a|\u00e0)\b|[.,;:]|$)",
        rf"\bcours\s+(?:{type_marker}\s+)?(?:(?:de|d'|en|sur)\s+)?(.{{2,120}}?)(?=\s+(?:ce\s+cours|cours\s+est|pour|le|la|qui|avec|a|\u00e0)\b|[.,;:]|$)",
    ]
    for pattern in patterns:
        for match in re.finditer(pattern, source, flags=re.IGNORECASE):
            value = clean_training_name_candidate(match.group(1))
            if value:
                return sentence_case(value[:90])
    return ""


def clean_training_name_candidate(value: str) -> str:
    clean = normalize_ws(value).strip(" ,;:-")
    if not clean:
        return ""

    candidate = re.sub(r"^(?:une|un|la|le|formation|cours|certification)\s+", "", clean, flags=re.IGNORECASE)
    candidate = re.sub(
        r"^(?:certification|certifiante|externe|interne|professionnel|professionnelle)\s+(?:(?:de|d'|en|sur)\s+)?",
        "",
        candidate,
        flags=re.IGNORECASE,
    )
    candidate = re.sub(
        r"\b(?:cette\s+formation|ce\s+cours|formation\s+est|cours\s+est|type\s+de\s+formation|la\s+formation\s+est)\b.*$",
        "",
        candidate,
        flags=re.IGNORECASE,
    )
    candidate = re.sub(r"\b\d{1,2}\s+(?:janvier|fevrier|mars|avril|mai|juin|juillet|aout|septembre|octobre|novembre|decembre)(?:\s+\d{4})?\b.*$", "", candidate, flags=re.IGNORECASE)
    candidate = re.sub(r"\b(?:le|du|au)\s+\d{1,2}(?:[/-]\d{1,2})?(?:[/-]\d{2,4})?\b.*$", "", candidate, flags=re.IGNORECASE)
    candidate = re.sub(r"\s+(?:de|du|des|d'|depuis)\s+[A-Za-zÀ-ÿ' -]{2,80}\s+vers\s+[A-Za-zÀ-ÿ' -]{2,80}\b.*$", "", candidate, flags=re.IGNORECASE)
    candidate = re.sub(r"\s+vers\s+[A-Za-zÀ-ÿ' -]{2,80}\b.*$", "", candidate, flags=re.IGNORECASE)
    candidate = re.split(r"\b(?:pour|afin|car|avec|transport|deplacement|trajet)\b", candidate, maxsplit=1, flags=re.IGNORECASE)[0]
    candidate = normalize_ws(candidate).strip(" ,;:-")
    return "" if norm(candidate) in {"formation", "cours", *FORMATION_TYPE_MARKERS} else candidate


def extract_location(source: str, key_text: str) -> str:
    clean = normalize_ws(source)
    lowered_key = norm(key_text)
    route_start, route_end = extract_route_locations(source)
    if any(token in lowered_key for token in ["depart", "origine"]):
        if route_start:
            return route_start
        match = re.search(r"\b(?:de|depuis|depart\s+de)\s+([\w' -]{2,70}?)(?=\s+(?:vers|a|\u00e0|pour)\b|[.,;:]|$)", clean, flags=re.IGNORECASE)
        if match:
            return clean_location_candidate(match.group(1))
    if any(token in lowered_key for token in ["destination", "arrivee", "souhaite"]):
        if route_end:
            return route_end
        match = re.search(r"\b(?:vers|a|\u00e0|destination\s+de?)\s+([\w' -]{2,70}?)(?=\s+(?:pour|le|la|qui|avec)\b|[.,;:]|$)", clean, flags=re.IGNORECASE)
        if match:
            return clean_location_candidate(match.group(1))
    match = re.search(r"\b(?:a|\u00e0|au|aux|dans|salle)\s+([\w' -]{2,70}?)(?=\s+(?:pour|le|la|qui|avec|debut|d\u00e9bute)\b|[.,;:]|$)", clean, flags=re.IGNORECASE)
    return clean_location_candidate(match.group(1)) if match else ""


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

        if value_has_prompt_evidence(source, value):
            return value[:220 if role in {"reason", "generic"} else 100]

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


def extract_value(
    source: str,
    field: LearnedField,
    examples: list[dict[str, Any]] | None = None,
    schema_roles: set[str] | None = None,
) -> str:
    haystack = norm(f"{field.key} {field.label}")
    options = field.options or []
    role = field_role(field)
    schema_roles = schema_roles or set()

    if field.type == "select":
        source_norm = norm(source)
        for option in options:
            option_norm = norm(option)
            if option_norm and option_norm in source_norm:
                return option

        generic_option_tokens = {"autre", "category", "categorie", "demande", "formation", "nature", "option", "type"}
        for option in options:
            tokens = [
                token
                for token in tokenize(option, include_bigrams=False)
                if len(token) >= 3 and token not in generic_option_tokens
            ]
            if tokens and all(contains_norm_term(source_norm, token) for token in tokens):
                return option

        learned = extract_from_learned_examples(source, field, examples or [])
        return coerce_select(learned, options)

    if role != "organization" and value_has_prompt_evidence(source, field.value):
        return field.value[:220 if role in {"reason", "generic"} else 100]

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
        value = extract_specification(source)
        if value:
            return value
        value = extract_object_qualifier(source)
        if value:
            return value
        return ""

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
        value = clause_after(source, ["destinataire", "organisme", "beneficiaire", "chez"], 80)
        return value

    if role == "location":
        return extract_location(source, haystack)

    if role == "object":
        if "specification" in schema_roles:
            value = split_requested_object(source)[0]
            if value:
                return value
        value = extract_requested_object(source)
        if value and not is_generic_request_object(value):
            return value

    if role == "category":
        if "transport" in haystack:
            return extract_transport_mode(source, options)
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


def field_has_prompt_evidence(source: str, field: LearnedField) -> bool:
    clean = norm(source)
    if not clean:
        return False

    haystack = norm(f"{field.key} {field.label}")
    role = field_role(field)

    if field.type != "select" and role != "organization" and value_has_prompt_evidence(source, field.value):
        return True

    if field.type == "select":
        options = field.options or []
        generic_option_tokens = {"autre", "category", "categorie", "demande", "formation", "nature", "option", "type"}
        for option in options:
            option_norm = norm(option)
            if option_norm and contains_norm_term(clean, option_norm):
                return True
            tokens = [
                token
                for token in tokenize(option, include_bigrams=False)
                if len(token) >= 3 and token not in generic_option_tokens
            ]
            if tokens and all(contains_norm_term(clean, token) for token in tokens):
                return True
        return False

    if role in {"date", "number", "email", "phone", "time", "period", "location", "training", "specification", "object"}:
        return source_supports_role(source, role, "specification" in haystack)

    if role == "category":
        if "transport" in haystack:
            return bool(extract_transport_mode(source, field.options or []))
        return bool(shift_variant(source) or extract_requested_object(source) or value_has_prompt_evidence(source, field.value))

    if role == "reason":
        return source_has_reason_evidence(source)

    if role == "attestation":
        return contains_norm_term(clean, "attestation")

    if role == "room":
        return re.search(r"\b(?:salle|room|reservation)\b", clean) is not None

    if role == "organization":
        return source_has_organization_evidence(source)

    key_tokens = [
        token
        for token in tokenize(f"{field.key} {field.label}", include_bigrams=False)
        if token not in {"ai", "custom", "champ", "demande", "type", "autre"}
    ]
    return bool(key_tokens and any(contains_norm_term(clean, token) for token in key_tokens))


def role_profile(fields: list[LearnedField]) -> set[str]:
    return {field_role(field) for field in fields if field_role(field) != "generic"}


def schemas_compatible(anchor: RankedSample, candidate: RankedSample) -> bool:
    if candidate is anchor:
        return True

    anchor_roles = role_profile(anchor.fields)
    candidate_roles = role_profile(candidate.fields)
    if not anchor_roles or not candidate_roles:
        return False

    overlap = anchor_roles & candidate_roles
    role_coverage = len(overlap) / max(1, min(len(anchor_roles), len(candidate_roles)))
    if role_coverage < 0.5:
        return False

    if candidate.score < max(0.34, anchor.score * 0.72):
        return False

    return True


def date_field_rank(field: LearnedField) -> int:
    haystack = norm(f"{field.key} {field.label}")
    if field.key == "dateSouhaiteeAutre":
        return 0
    if "metier" not in haystack and "extra" not in haystack:
        return 1
    return 2


def dedupe_selected_fields(fields: list[LearnedField]) -> list[LearnedField]:
    date_indexes: dict[str, int] = {}
    cleaned: list[LearnedField] = []

    for field in fields:
        role = field_role(field)
        value = normalize_ws(field.value)

        if role == "object" and is_generic_request_object(value):
            if field.required:
                field = LearnedField(field.key, field.label, field.type, field.required, "", field.options or [], field.source)
            else:
                continue

        if role == "category" and "transport" in norm(f"{field.key} {field.label}") and value and not extract_transport_mode(value):
            if field.required:
                field = LearnedField(field.key, field.label, field.type, field.required, "", field.options or [], field.source)
            else:
                continue

        if role == "date" and value:
            existing_index = date_indexes.get(value)
            if existing_index is not None:
                existing = cleaned[existing_index]
                if date_field_rank(field) < date_field_rank(existing):
                    cleaned[existing_index] = field
                continue
            date_indexes[value] = len(cleaned)

        cleaned.append(field)

    return cleaned


def field_family(field: LearnedField) -> str:
    role = field_role(field)
    text = norm(f"{field.key} {field.label}")
    tokens = [
        token
        for token in re.findall(r"[a-z0-9]+", text)
        if token not in {"ai", "custom", "champ", "demande", "souhaite", "souhaitee", "type", "autre"}
    ]
    return f"{role}:{'_'.join(tokens[:4]) if tokens else norm(field.key)}"


def field_from_payload(payload: dict[str, Any], fallback_key: str = "") -> LearnedField | None:
    key = normalize_ws(payload.get("key") or fallback_key)
    if not key:
        return None
    label = normalize_ws(payload.get("label")) or label_from_key(key)
    field_type = norm(payload.get("type") or infer_field_type(key, label, normalize_ws(payload.get("value"))))
    if field_type not in {"text", "textarea", "select", "number", "date"}:
        field_type = infer_field_type(key, label, normalize_ws(payload.get("value")))
    return LearnedField(
        key=key,
        label=label,
        type=field_type,
        required=to_bool(payload.get("required")),
        value=normalize_ws(payload.get("value")),
        options=normalize_options(payload.get("options")),
        source=normalize_ws(payload.get("source")) or "generated",
    )


def suppression_signatures(field: LearnedField) -> set[str]:
    role = field_role(field)
    return {
        f"key:{norm(field.key)}",
        f"family:{field_family(field)}",
        f"role:{role}:{norm(field.label)}",
    }


def field_is_explicitly_named(source: str, field: LearnedField) -> bool:
    clean = norm(source)
    if not clean:
        return False

    for option in field.options or []:
        option_norm = norm(option)
        if option_norm and contains_norm_term(clean, option_norm):
            return True

    tokens = [
        token
        for token in tokenize(f"{field.key} {field.label}", include_bigrams=False)
        if token not in {"ai", "custom", "champ", "demande", "souhaite", "souhaitee", "type", "autre"}
    ]
    return bool(tokens and any(contains_norm_term(clean, token) for token in tokens))


def extract_suppression_signals(samples: list[dict[str, Any]]) -> set[str]:
    signals: set[str] = set()

    for sample in samples:
        if not isinstance(sample, dict):
            continue

        explicit = sample.get("suppressedFields")
        if isinstance(explicit, list):
            for item in explicit:
                if not isinstance(item, dict):
                    continue
                field = field_from_payload(item)
                if field:
                    signals.update(suppression_signatures(field))

        snapshot = sample.get("generatedSnapshot") if isinstance(sample.get("generatedSnapshot"), dict) else {}
        plan = snapshot.get("dynamicFieldPlan") if isinstance(snapshot.get("dynamicFieldPlan"), dict) else {}
        generated_fields = plan.get("add") if isinstance(plan.get("add"), list) else []
        generated_details = snapshot.get("suggestedDetails") if isinstance(snapshot.get("suggestedDetails"), dict) else {}
        final_details = sample_details(sample)
        final_plan = sample.get("fieldPlan") if isinstance(sample.get("fieldPlan"), dict) else {}
        final_add = final_plan.get("add") if isinstance(final_plan.get("add"), list) else []
        kept_keys = {
            normalize_ws(item.get("key"))
            for item in final_add
            if isinstance(item, dict) and normalize_ws(item.get("key"))
        } | set(final_details.keys())

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


def suppresses_field(field: LearnedField, signals: set[str], source: str) -> bool:
    if not signals:
        return False
    if field_is_explicitly_named(source, field):
        return False
    return bool(suppression_signatures(field) & signals)


def select_learned_fields(source: str, ranked: list[RankedSample], max_fields: int = 8) -> tuple[list[LearnedField], float, bool]:
    if not ranked:
        return [], 0.0, False

    anchor = ranked[0]
    ranked = [sample for sample in ranked if schemas_compatible(anchor, sample)]
    anchor_keys = {
        normalize_ws(field.key)
        for field in anchor.fields
        if normalize_ws(field.key) and normalize_ws(field.key) not in BASE_DETAIL_KEYS
    }

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
            if key not in anchor_keys:
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

    schema_roles = {field_role(data["field"]) for data in scores.values() if isinstance(data.get("field"), LearnedField)}
    selected: list[LearnedField] = []
    for key, data in scores.items():
        weight = float(data["weight"])
        source_field: LearnedField = data["field"]
        minimum = 0.28 if source_field.source == "manual" or strongest_manual else 0.36
        if weight < minimum and ranked[0].score < 0.64:
            continue

        required = source_field.required or float(data["required"]) >= max(0.35, weight * 0.45)
        has_prompt_evidence = field_has_prompt_evidence(source, source_field)
        value = extract_value(source, source_field, data.get("examples", []), schema_roles) if has_prompt_evidence else ""

        if not value and not has_prompt_evidence and not required:
            continue

        field_type = source_field.type
        if source_field.options and field_type != "select":
            field_type = "select"

        selected.append(
            LearnedField(
                key=source_field.key,
                label=source_field.label,
                type=field_type,
                required=required,
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
    selected = dedupe_selected_fields(selected)
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

    feedback_samples = extract_feedback_samples(request_data)
    suppression_signals = extract_suppression_signals(feedback_samples)
    ranked = rank_feedback(source, feedback_samples)
    learned_fields, match_score, manual_schema = select_learned_fields(source, ranked)
    learned_fields = [field for field in learned_fields if not suppresses_field(field, suppression_signals, source)]
    has_usable_match = bool(ranked) and match_score >= MIN_USABLE_MATCH_SCORE and bool(learned_fields)
    used_fallback = not has_usable_match
    fields = learned_fields if has_usable_match else fallback_fields(source)
    fields = [field for field in fields if not suppresses_field(field, suppression_signals, source)]

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
