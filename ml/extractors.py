from __future__ import annotations

from calendar import monthrange
from dataclasses import dataclass, field
from datetime import datetime, timedelta
import re
import unicodedata
from typing import Any, Iterable


MONTHS = {
    "janvier": 1,
    "fevrier": 2,
    "février": 2,
    "mars": 3,
    "avril": 4,
    "mai": 5,
    "juin": 6,
    "juillet": 7,
    "aout": 8,
    "août": 8,
    "septembre": 9,
    "octobre": 10,
    "novembre": 11,
    "decembre": 12,
    "décembre": 12,
}

WEEKDAYS = {
    "lundi": 0,
    "mardi": 1,
    "mercredi": 2,
    "jeudi": 3,
    "vendredi": 4,
    "samedi": 5,
    "dimanche": 6,
}

WEEKDAY_PLURALS = {
    "lundi": "lundis",
    "mardi": "mardis",
    "mercredi": "mercredis",
    "jeudi": "jeudis",
    "vendredi": "vendredis",
    "samedi": "samedis",
    "dimanche": "dimanches",
}

STOPWORDS = {
    "a",
    "afin",
    "ai",
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
    "en",
    "est",
    "et",
    "faire",
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

ROUTE_STOP_MARKERS = {
    "uniquement",
    "seulement",
    "exclusivement",
    "en",
    "pour",
    "afin",
    "car",
    "avec",
    "sans",
    "pas",
    "le",
    "la",
    "les",
    "un",
    "une",
    "du",
    "de",
    "des",
    "a partir",
    "depuis",
    "jusqu",
    "et",
    "ou",
    "qui",
    "que",
}

NON_LOCATION_ENTITIES = {
    "client",
    "clients",
    "fournisseur",
    "fournisseurs",
    "societe",
    "société",
    "entreprise",
    "partenaire",
}

GENERIC_OBJECTS = {
    "demande",
    "deplacement",
    "déplacement",
    "formation",
    "information",
    "materiel",
    "matériel",
    "objet",
    "horaire",
    "poste",
    "shift",
    "transport",
    "trajet",
    "voyage",
}

TRANSPORT_MODES = {
    "bus": "Bus",
    "taxi": "Taxi",
    "train": "Train",
    "metro": "Metro",
    "métro": "Metro",
    "navette": "Navette",
    "avion": "Avion",
    "vol": "Avion",
    "voiture": "Voiture de service",
    "vehicule": "Voiture de service",
    "véhicule": "Voiture de service",
}

MATERIAL_TERMS = [
    "micro casque",
    "casque antibruit",
    "station d accueil",
    "station d'accueil",
    "docking station",
    "ecran",
    "écran",
    "clavier",
    "souris",
    "casque",
    "webcam",
    "ordinateur",
    "laptop",
    "pc",
    "imprimante",
    "dock",
]

ORG_TERMS = {
    "banque",
    "ambassade",
    "consulat",
    "ecole",
    "école",
    "universite",
    "université",
    "administration",
    "ministere",
    "ministère",
}


@dataclass(frozen=True)
class ExtractedEntities:
    normalized_text: str
    tokens: list[str] = field(default_factory=list)
    date_start: str = ""
    date_end: str = ""
    time_start: str = ""
    time_end: str = ""
    time_range: str = ""
    amount: str = ""
    route_from: str = ""
    route_to: str = ""
    constraints: list[str] = field(default_factory=list)
    transport_mode: str = ""
    requested_object: str = ""
    specification: str = ""
    training_name: str = ""
    attestation_type: str = ""
    organization: str = ""
    reason: str = ""
    parking_zone: str = ""
    expense_type: str = ""
    leave_type: str = ""
    schedule_current: str = ""
    schedule_target: str = ""


def normalize_ws(value: Any) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip()


def strip_accents(value: Any) -> str:
    text = unicodedata.normalize("NFD", str(value or ""))
    return "".join(ch for ch in text if unicodedata.category(ch) != "Mn")


def norm(value: Any) -> str:
    text = strip_accents(value)
    text = text.replace("\u2019", "'").replace("`", "'")
    text = text.replace("-", " ")
    text = re.sub(r"[^a-zA-Z0-9+#/.' ]+", " ", text)
    return normalize_ws(text).lower()


def sentence_case(value: Any) -> str:
    text = normalize_ws(value)
    if not text:
        return ""
    return text[:1].upper() + text[1:]


def smart_title(value: Any) -> str:
    text = normalize_ws(value)
    if not text:
        return ""
    keep_upper = {"ui", "ux", "ui/ux", "crm", "vpn", "jira", "pc"}
    pieces: list[str] = []
    for part in text.split():
        clean = strip_accents(part).lower()
        if clean in keep_upper:
            pieces.append(part.upper())
        elif len(part) == 1 and part.isalpha():
            pieces.append(part.upper())
        else:
            pieces.append(part[:1].upper() + part[1:].lower())
    return " ".join(pieces)


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


def has_word(text: Any, word: str) -> bool:
    clean = norm(text)
    target = norm(word)
    if not clean or not target:
        return False
    return re.search(rf"(?<![a-z0-9]){re.escape(target)}(?![a-z0-9])", clean) is not None


def has_any_word(text: Any, words: Iterable[str]) -> bool:
    return any(has_word(text, word) for word in words)


def has_phrase(text: Any, phrase: str) -> bool:
    clean = norm(text)
    target = norm(phrase)
    if not clean or not target:
        return False
    pattern = r"(?<![a-z0-9])" + re.escape(target).replace(r"\ ", r"\s+") + r"(?![a-z0-9])"
    return re.search(pattern, clean) is not None


def has_any_phrase(text: Any, phrases: Iterable[str]) -> bool:
    return any(has_phrase(text, phrase) for phrase in phrases)


def contains_term(text: Any, term: Any) -> bool:
    clean_term = norm(term)
    if not clean_term:
        return False
    if " " in clean_term:
        return has_phrase(text, clean_term)
    return has_word(text, clean_term)


def _safe_date(year: int, month: int, day: int) -> str:
    try:
        return datetime(year, month, day).date().isoformat()
    except ValueError:
        return ""


def _shift_months(base: datetime, offset: int) -> datetime:
    month_index = base.month - 1 + offset
    year = base.year + month_index // 12
    month = month_index % 12 + 1
    day = min(base.day, monthrange(year, month)[1])
    return base.replace(year=year, month=month, day=day)


def _small_number(value: str) -> int:
    clean = norm(value)
    if clean in {"un", "une"}:
        return 1
    try:
        return max(0, int(clean))
    except ValueError:
        return 0


def _next_weekday_date(now: datetime, weekday: int) -> str:
    days = (weekday - now.weekday()) % 7
    return (now.date() + timedelta(days=days)).isoformat()


def extract_period_label(text: Any) -> str:
    clean = norm(text)
    if not clean:
        return ""

    weekday_pattern = "|".join(WEEKDAYS)
    recurring = re.search(rf"\b(?:tous|toutes|chaque)\s+les?\s+({weekday_pattern})s?\b", clean)
    if recurring:
        day = recurring.group(1)
        return f"Tous les {WEEKDAY_PLURALS.get(day, day + 's')}"

    if re.search(r"\bsemaine prochaine\b", clean):
        return "Semaine prochaine"
    if re.search(r"\bcette semaine\b|\bsemaine en cours\b", clean):
        return "Semaine en cours"
    if re.search(r"\bmois prochain\b", clean):
        return "Mois prochain"
    if re.search(r"\bce mois\b|\bmois en cours\b", clean):
        return "Mois en cours"
    return ""


def extract_date(text: Any, today: datetime | None = None) -> str:
    clean = norm(text)
    if not clean:
        return ""
    now = today or datetime.now()

    relative = [
        (r"\baujourd hui\b", 0),
        (r"\bdemain\b", 1),
        (r"\bapres demain\b", 2),
    ]
    for pattern, days in relative:
        if re.search(pattern, clean):
            return (now.date() + timedelta(days=days)).isoformat()

    day_offset = re.search(r"\b(?:dans|apres|d ici)\s+(un|une|\d+)\s+jours?\b", clean)
    if day_offset:
        return (now.date() + timedelta(days=_small_number(day_offset.group(1)))).isoformat()

    week_offset = re.search(r"\b(?:dans|apres|d ici)\s+(un|une|\d+)\s+semaines?\b", clean)
    if week_offset:
        return (now.date() + timedelta(days=7 * _small_number(week_offset.group(1)))).isoformat()

    if re.search(r"\bsemaine prochaine\b", clean):
        return (now.date() + timedelta(days=7)).isoformat()

    if re.search(r"\bmois prochain\b", clean):
        return _shift_months(now, 1).date().isoformat()

    weekday_pattern = "|".join(WEEKDAYS)
    weekday_match = re.search(rf"\b(?:ce\s+|prochain\s+|prochaine\s+)?({weekday_pattern})s?\b", clean)
    if weekday_match and not re.search(rf"\b(?:tous|toutes|chaque)\s+les?\s+{weekday_match.group(1)}s?\b", clean):
        return _next_weekday_date(now, WEEKDAYS[weekday_match.group(1)])

    match = re.search(r"\b(\d{4})-(\d{1,2})-(\d{1,2})\b", clean)
    if match:
        year, month, day = map(int, match.groups())
        return _safe_date(year, month, day)

    match = re.search(r"\b(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?\b", clean)
    if match:
        day = int(match.group(1))
        month = int(match.group(2))
        year = int(match.group(3) or now.year)
        if year < 100:
            year += 2000
        parsed = _safe_date(year, month, day)
        if parsed and match.group(3) is None and datetime.fromisoformat(parsed).date() < now.date():
            parsed = _safe_date(year + 1, month, day)
        return parsed

    month_pattern = "|".join(re.escape(strip_accents(name).lower()) for name in MONTHS)
    month_map = {strip_accents(name).lower(): value for name, value in MONTHS.items()}
    match = re.search(rf"\b(\d{{1,2}})(?:er)?\s+({month_pattern})(?:\s+(\d{{4}}))?\b", clean)
    if match:
        day = int(match.group(1))
        month = month_map.get(match.group(2), now.month)
        year = int(match.group(3) or now.year)
        parsed = _safe_date(year, month, day)
        if parsed and match.group(3) is None and datetime.fromisoformat(parsed).date() < now.date():
            parsed = _safe_date(year + 1, month, day)
        return parsed

    return ""


def extract_date_range(text: Any) -> tuple[str, str]:
    source = normalize_ws(text)
    clean = norm(source)
    if not clean:
        return "", ""

    month_pattern = "|".join(re.escape(strip_accents(name).lower()) for name in MONTHS)
    range_match = re.search(
        rf"\bdu\s+(\d{{1,2}})(?:er)?(?:\s+({month_pattern}))?\s+au\s+(\d{{1,2}})(?:er)?(?:\s+({month_pattern}))?\b",
        clean,
    )
    if range_match:
        now = datetime.now()
        month_map = {strip_accents(name).lower(): value for name, value in MONTHS.items()}
        start_month = month_map.get(range_match.group(2) or "", now.month)
        end_month = month_map.get(range_match.group(4) or "", start_month)
        start = _safe_date(now.year, start_month, int(range_match.group(1)))
        end = _safe_date(now.year, end_month, int(range_match.group(3)))
        if start and datetime.fromisoformat(start).date() < now.date():
            start = _safe_date(now.year + 1, start_month, int(range_match.group(1)))
            end = _safe_date(now.year + 1, end_month, int(range_match.group(3)))
        return start, end

    first = extract_date(source)
    if not first:
        return "", ""

    # Remove the first date-like token and try once more for an end date.
    without_first = re.sub(
        r"\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?|\d{1,2}(?:er)?\s+[A-Za-zÀ-ÿ]+)\b",
        " ",
        source,
        count=1,
        flags=re.IGNORECASE,
    )
    second = extract_date(without_first)
    return first, second


def extract_time_range(text: Any) -> tuple[str, str, str]:
    source = normalize_ws(text)
    if not source:
        return "", "", ""

    def format_hour(hour: str, minute: str = "") -> str:
        try:
            parsed_hour = max(0, min(23, int(hour)))
        except ValueError:
            return ""
        minute_digits = re.sub(r"\D+", "", minute or "")
        if not minute_digits or minute_digits == "00":
            return f"{parsed_hour}h"
        return f"{parsed_hour}h{minute_digits[:2].zfill(2)}"

    range_patterns = [
        r"\b(?:de\s+)?([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?\s*(?:-|a|à|to|jusqu(?:'|e)?a|jusqu(?:'|e)?à)\s*([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?\b",
        r"\bde\s+([01]?\d|2[0-3])\s+heures?\s+(?:a|à)\s+([01]?\d|2[0-3])\s+heures?\b",
    ]
    for pattern in range_patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        if len(match.groups()) == 4:
            start = format_hour(match.group(1), match.group(2) or "")
            end = format_hour(match.group(3), match.group(4) or "")
        else:
            start = format_hour(match.group(1))
            end = format_hour(match.group(2))
        return start, end, f"{start}-{end}" if start and end else ""

    match = re.search(r"\b([01]?\d|2[0-3])\s*(?:h|:)\s*([0-5]\d)?\b", source, flags=re.IGNORECASE)
    if match:
        start = format_hour(match.group(1), match.group(2) or "")
        return start, "", start

    return "", "", ""


def extract_amount(text: Any) -> str:
    clean = norm(text)
    match = re.search(r"\b(\d+(?:[.,]\d+)?)\s*(?:tnd|dt|dinar|dinars|eur|euro|euros|\$)\b", clean)
    if match:
        return match.group(1).replace(",", ".")
    return ""


def extract_first_number(text: Any) -> str:
    amount = extract_amount(text)
    if amount:
        return amount
    match = re.search(r"\b(\d+(?:[.,]\d+)?)\b", norm(text))
    return match.group(1).replace(",", ".") if match else ""


def _cut_at_route_stop(value: str) -> str:
    clean = normalize_ws(value)
    if not clean:
        return ""
    lowered = norm(clean)
    cut = len(clean)

    for marker in sorted(ROUTE_STOP_MARKERS, key=len, reverse=True):
        marker_norm = norm(marker)
        pattern = r"\b" + re.escape(marker_norm).replace(r"\ ", r"\s+") + r"\b"
        match = re.search(pattern, lowered)
        if match:
            cut = min(cut, match.start())

    digit = re.search(r"\d", lowered)
    if digit:
        cut = min(cut, digit.start())

    return normalize_ws(clean[:cut]).strip(" ,;:-/'")


def _clean_location(value: Any) -> str:
    text = _cut_at_route_stop(str(value or ""))
    text = re.sub(r"^(?:l[' ]|d[' ]|de\s+|du\s+|des\s+|le\s+|la\s+|les\s+)", "", text, flags=re.IGNORECASE)
    text = normalize_ws(text).strip(" ,;:-/'")
    if not text:
        return ""

    parts = [part for part in re.split(r"\s+", text) if part]
    if not parts:
        return ""
    normalized_parts = [norm(part) for part in parts]
    if any(part in NON_LOCATION_ENTITIES for part in normalized_parts):
        return ""
    if any(part in MONTHS for part in normalized_parts):
        return ""
    if norm(" ".join(parts)) in GENERIC_OBJECTS:
        return ""
    if len(" ".join(parts)) < 2:
        return ""
    return smart_title(" ".join(parts[:4]))


def extract_route(text: Any) -> tuple[str, str]:
    source = normalize_ws(text)
    if not source:
        return "", ""

    for match in re.finditer(r"\b(?:vers|jusqu[' ]?a|jusqu[' ]?à)\b", source, flags=re.IGNORECASE):
        left = source[:match.start()]
        right = source[match.end():]
        starts = list(re.finditer(r"\b(?:de|du|depuis)\b", left, flags=re.IGNORECASE))
        if not starts:
            continue
        start_marker = starts[-1]
        route_from = _clean_location(left[start_marker.end():])
        route_to = _clean_location(right)
        if route_from and route_to and norm(route_from) != norm(route_to):
            return route_from, route_to

    for match in re.finditer(r"\b(?:a|à|au)\b", source, flags=re.IGNORECASE):
        left = source[:match.start()]
        right = source[match.end():]
        starts = list(re.finditer(r"\b(?:de|du|depuis)\b", left, flags=re.IGNORECASE))
        if not starts:
            continue
        start_marker = starts[-1]
        route_from = _clean_location(left[start_marker.end():])
        route_to = _clean_location(right)
        if route_from and route_to and norm(route_from) != norm(route_to):
            return route_from, route_to

    route_patterns = [
        r"\b(?:de|du|depuis)\s+(.+?)\s+(?:vers|jusqu[' ]?a|jusqu[' ]?à)\s+(.+?)(?=$|[,.;])",
        r"\b(?:de|du|depuis)\s+(.+?)\s+(?:a|à|au)\s+(.+?)(?=$|[,.;])",
        r"\bdepart\s*[:=-]?\s*(.+?)\s+(?:destination|arrivee|arrivée)\s*[:=-]?\s*(.+?)(?=$|[,.;])",
    ]
    for pattern in route_patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        start = _clean_location(match.group(1))
        end = _clean_location(match.group(2))
        if start and end and norm(start) != norm(end):
            return start, end

    return "", ""


def extract_constraints(text: Any) -> list[str]:
    source = normalize_ws(text)
    if not source:
        return []

    constraints: list[str] = []
    patterns = [
        (r"\b(uniquement|seulement|exclusivement)\s+(?:en\s+)?([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{1,40})", "Uniquement"),
        (r"\b(sans)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{1,40})", "Sans"),
        (r"\b(pas\s+de)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{1,40})", "Pas de"),
        (r"\b(eviter|éviter)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{1,40})", "Eviter"),
        (r"\b(obligatoire|imperatif|impératif)\b(?:\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{1,40}))?", "Obligatoire"),
    ]
    for pattern, label in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        value = _cut_at_route_stop(match.group(2) if len(match.groups()) >= 2 else "")
        text_value = normalize_ws(f"{label} {value}").strip()
        if text_value and text_value not in constraints:
            constraints.append(sentence_case(text_value))
    return constraints


def extract_transport_mode(text: Any, options: Iterable[str] | None = None) -> str:
    source = norm(text)
    if not source:
        return ""

    option_values = list(options or [])
    for option in option_values:
        if option and contains_term(source, option):
            return normalize_ws(option)

    for term, label in TRANSPORT_MODES.items():
        if has_word(source, term):
            return label
    return ""


def has_formation_signal(text: Any) -> bool:
    return has_any_word(text, ["formation", "certification", "cours", "atelier", "coaching"])


def extract_training_name(text: Any) -> str:
    if not has_formation_signal(text):
        return ""

    source = normalize_ws(text)
    patterns = [
        r"\b(?:formation|cours|atelier|coaching)\s+(?:professionnelle?|avancee?|avancée?|externe|interne|certifiante)?\s*(?:en|sur|de|du)\s+(.+?)(?=\s+(?:cette|ce|est|pour|afin|car|de|du|vers|a|à|le|la|les|qui|debut|d[ée]bute|commence)\b|\d|[,.;]|$)",
        r"\b(?:formation|cours|atelier|coaching)\s+(?!professionnelle?\b|avancee?\b|avancée?\b|externe\b|interne\b)(.+?)(?=\s+(?:cette|ce|est|pour|afin|car|de|du|vers|a|à|le|la|les|qui|debut|d[ée]bute|commence)\b|\d|[,.;]|$)",
        r"\bcertification\s+(?:en|sur|de|du)?\s*(.+?)(?=\s+(?:cette|ce|est|pour|afin|car|de|du|vers|a|à|le|la|les|qui|debut|d[ée]bute|commence)\b|\d|[,.;]|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = clean_entity_text(match.group(1))
        candidate = re.sub(r"\b(?:externe|interne|certifiante?)$", "", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"^(?:externe|interne|certifiante?)\s+", "", candidate, flags=re.IGNORECASE)
        candidate = normalize_ws(candidate)
        if candidate and not has_word(candidate, "professionnelle"):
            return smart_title(candidate)
    return ""


def clean_entity_text(value: Any) -> str:
    text = normalize_ws(value)
    if not text:
        return ""
    text = re.sub(r"\b(?:pour|afin|car|avec|sans|le|la|les|du|de|des|en|uniquement|seulement|exclusivement)\b.*$", "", text, flags=re.IGNORECASE)
    text = re.sub(r"\b\d{4}-\d{1,2}-\d{1,2}\b", "", text)
    text = re.sub(r"\b\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?\b", "", text)
    return normalize_ws(text).strip(" ,;:-/'")


def extract_requested_object(text: Any) -> str:
    source = normalize_ws(text)
    clean = norm(source)
    if not clean:
        return ""

    for term in sorted(MATERIAL_TERMS, key=len, reverse=True):
        if has_phrase(clean, term) or has_word(clean, term):
            return norm(term)

    patterns = [
        r"\b(?:besoin\s+de|demande\s+de|je\s+veux|je\s+souhaite|il\s+me\s+faut)\s+(?:un|une|des|du|de\s+l[' ]|d[' ])?\s+(.+?)(?=\s+(?:pour|afin|car|avec|sans|le|la|les|du|de|des|a partir|à partir)\b|[,.;]|$)",
        r"\b(?:remboursement|avance|attestation|parking|transport|nettoyage|maintenance|acces|accès|conge|congé)\b(?:\s+de)?\s+(.+?)(?=\s+(?:pour|afin|car|avec|sans|le|la|les|du|de|des)\b|[,.;]|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = clean_entity_text(match.group(1))
        if not candidate:
            continue
        candidate_norm = norm(candidate)
        if candidate_norm in GENERIC_OBJECTS:
            continue
        if candidate_norm.startswith("par "):
            continue
        if has_any_word(candidate_norm, TRANSPORT_MODES.keys()):
            continue
        if len(candidate_norm) < 3:
            continue
        return candidate_norm[:80]

    return ""


def extract_specification(text: Any) -> str:
    source = normalize_ws(text)
    if not source:
        return ""

    measure = re.search(r"\b(\d+(?:[.,]\d+)?\s*(?:pouces?|inch|inches|\"|cm|mm|go|gb|to|tb|mo|mb|hz|mah|w|watts?))\b", source, flags=re.IGNORECASE)
    if measure:
        return normalize_ws(measure.group(1)).replace(",", ".").lower()

    for term in sorted(MATERIAL_TERMS, key=len, reverse=True):
        pattern = rf"\b{re.escape(term)}\b\s+(.+?)(?=\s+(?:pour|afin|car|avec|sans|le|la|les|du|de|des|a partir|à partir)\b|[,.;]|$)"
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = clean_entity_text(match.group(1))
        if candidate and norm(candidate) not in GENERIC_OBJECTS | {"urgent", "urgence"}:
            return norm(candidate)[:80]
    return ""


def extract_attestation_type(text: Any) -> str:
    source = normalize_ws(text)
    if not has_word(source, "attestation"):
        return ""
    match = re.search(r"\b(attestation\s+(?:de|du|d[' ])\s+[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,60})", source, flags=re.IGNORECASE)
    if match:
        candidate = normalize_ws(match.group(1))
        candidate = re.sub(r"\b(?:pour|afin|car|avec|sans|le|la|les|a|à)\b.*$", "", candidate, flags=re.IGNORECASE)
        return norm(candidate)
    return "attestation"


def extract_organization(text: Any) -> str:
    source = normalize_ws(text)
    clean = norm(source)
    if not source:
        return ""
    for term in ORG_TERMS:
        if has_word(clean, term):
            return smart_title(term)

    patterns = [
        r"\b(?:organisme|destinataire|beneficiaire|bénéficiaire|chez)\s*[:=-]?\s*([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,60})",
        r"\b(?:societe|société|entreprise)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,60})",
    ]
    for pattern in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = clean_entity_text(match.group(1))
        if candidate and norm(candidate) not in {"visa", "dossier", "mission"}:
            return smart_title(candidate)
    return ""


def extract_reason(text: Any) -> str:
    source = normalize_ws(text)
    if not source:
        return ""

    patterns = [
        r"\b(?:motif|raison|justification)\s*[:=-]?\s*(.+?)(?=[,.;]|$)",
        r"\b(?:car|parce\s+que|afin\s+de|suite\s+a|suite\s+à|pour\s+cause\s+de)\s+(.+?)(?=[,.;]|$)",
        r"\bmission\s+(.+?)(?=[,.;]|$)",
        r"\bpour\s+(tendinite|urgence|banque|visa|dossier\s+[A-Za-zÀ-ÿ'\- ]{2,40}|projet\s+[A-Za-zÀ-ÿ'\- ]{2,40})(?=[,.;]|$)",
        r"\bpour\s+(.+?)(?=[,.;]|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = clean_entity_text(match.group(1))
        if candidate:
            candidate = re.sub(r"\b(?:tous|toutes|chaque)\s*$", "", candidate, flags=re.IGNORECASE)
            candidate = re.sub(r"\b(?:depart|départ|arrivee|arrivée)\b.*$", "", candidate, flags=re.IGNORECASE)
            candidate = normalize_ws(candidate)
        if candidate:
            if pattern.startswith(r"\bmission"):
                return sentence_case(f"mission {candidate}")[:220]
            return candidate[:220]
    return ""


def extract_parking_zone(text: Any) -> str:
    source = normalize_ws(text)
    clean = norm(source)
    if not has_any_word(clean, ["parking", "stationnement"]):
        return ""

    patterns = [
        r"\b(pres\s+d[' ]?un?\s+[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,50})",
        r"\b(près\s+d[' ]?un?\s+[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,50})",
        r"\b(a\s+cote\s+de\s+[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,50})",
        r"\b(à\s+côté\s+de\s+[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ'\- ]{2,50})",
        r"\b(devant|derriere|derrière|entree|entrée|ascenseur|zone\s+[A-Za-z0-9]+|bloc\s+[A-Za-z0-9]+)\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = clean_entity_text(match.group(1))
        if candidate:
            return sentence_case(candidate)
    return ""


def extract_expense_type(text: Any) -> str:
    clean = norm(text)
    if has_any_word(clean, ["hotel", "hebergement", "nuitee"]):
        return "Hotel"
    if has_any_word(clean, ["restaurant", "repas", "dejeuner", "diner"]):
        return "Restaurant"
    if has_any_word(clean, ["transport", "taxi", "bus", "train", "avion", "vol"]):
        return "Transport"
    if has_any_word(clean, ["internet", "connexion", "wifi"]):
        return "Internet"
    if has_any_word(clean, ["medicament", "medicaments", "pharmacie"]):
        return "Medicaments"
    return ""


def extract_leave_type(text: Any) -> str:
    clean = norm(text)
    if not has_any_word(clean, ["conge", "absence", "arret", "repos"]):
        return ""
    if has_any_word(clean, ["maladie", "medical", "hospitalisation"]):
        return "Conge maladie"
    if has_any_word(clean, ["annuel", "vacances"]):
        return "Conge annuel"
    if has_phrase(clean, "sans solde"):
        return "Conge sans solde"
    return "Conge annuel"


def extract_schedule_change(text: Any) -> tuple[str, str]:
    source = normalize_ws(text)
    if not has_any_word(source, ["horaire", "shift", "poste"]):
        return "", ""
    start, end, full = extract_time_range(source)
    if start and end:
        return start, end
    if has_any_word(source, ["nuit", "nocturne"]):
        return "", "Nuit"
    if has_any_word(source, ["soir", "soiree"]):
        return "", "Soir"
    if has_any_word(source, ["matin", "jour"]):
        return "", "Jour"
    return "", full


def extract_entities(text: Any) -> ExtractedEntities:
    source = normalize_ws(text)
    date_start, date_end = extract_date_range(source)
    time_start, time_end, time_full = extract_time_range(source)
    route_from, route_to = extract_route(source)
    schedule_current, schedule_target = extract_schedule_change(source)

    return ExtractedEntities(
        normalized_text=norm(source),
        tokens=tokenize(source),
        date_start=date_start,
        date_end=date_end,
        time_start=time_start,
        time_end=time_end,
        time_range=time_full,
        amount=extract_amount(source),
        route_from=route_from,
        route_to=route_to,
        constraints=extract_constraints(source),
        transport_mode=extract_transport_mode(source),
        requested_object=extract_requested_object(source),
        specification=extract_specification(source),
        training_name=extract_training_name(source),
        attestation_type=extract_attestation_type(source),
        organization=extract_organization(source),
        reason=extract_reason(source),
        parking_zone=extract_parking_zone(source),
        expense_type=extract_expense_type(source),
        leave_type=extract_leave_type(source),
        schedule_current=schedule_current,
        schedule_target=schedule_target,
    )
