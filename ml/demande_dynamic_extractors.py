from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import dataclass
from calendar import monthrange
from datetime import datetime, timedelta
from pathlib import Path
import json
import re
import unicodedata
from typing import Any, Iterable

from text_match_utils import _has_any_phrase, _has_phrase


STOP_MARKERS = (
    "des",
    "le",
    "du",
    "de",
    "vers",
    "pour",
    "afin",
    "car",
    "avec",
    "a partir",
    "depuis",
    "jusqu",
    "et",
    "ou",
    "qui",
    "que",
)

BOUNDARY_REGEX = re.compile(
    r"(?:\b(?:des|le|du|de|vers|pour|afin|car|avec|depuis|jusqu|et|ou|qui|que)\b|\ba\s+partir\b|\b\d{4}\b|\b\d{1,2}[/-]\d{1,2}\b|\d)",
    re.IGNORECASE,
)

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


def _shift_date_by_months(base_date, months: int):
    try:
        month_index = base_date.month - 1 + int(months)
        year = base_date.year + (month_index // 12)
        month = (month_index % 12) + 1
        day = min(base_date.day, monthrange(year, month)[1])
        return base_date.replace(year=year, month=month, day=day)
    except ValueError:
        return base_date


def _extract_relative_duration_dates(text: str) -> tuple[str, str]:
    lowered = norm(text)
    if not lowered:
        return "", ""

    today = datetime.now().date()
    duration_match = re.search(r"\b(?:pendant|pour|durant|sur)\s+(\d+|un|une)\s+(jour|jours|mois)\b", lowered, flags=re.IGNORECASE)
    if not duration_match:
        return "", ""

    raw_amount = duration_match.group(1)
    amount = 1 if raw_amount in {"un", "une"} else int(raw_amount)
    unit = duration_match.group(2)

    start_date = today.isoformat()
    if unit in {"jour", "jours"}:
        end_date = (today + timedelta(days=amount)).isoformat()
    else:
        end_date = _shift_date_by_months(today, amount).isoformat()

    return start_date, end_date

PRIORITY_SIGNALS = {
    "HAUTE": ["urgent", "urgence", "des aujourd hui", "immédiatement", "immediatement", "medical", "hospitalisation", "accident", "bloquant"],
    "NORMALE": ["des que possible", "bientot", "formation", "certification", "deplacement"],
    "BASSE": ["quand possible", "pas urgent", "confort"],
}

LEAVE_REQUEST_TERMS = ["conge", "congé", "arret", "arrêt", "absence", "repos"]
LEAVE_TYPE_HINTS = {
    "Conge maladie": ["maladie", "medical", "médical", "hospitalisation"],
    "Conge maternite": ["maternite", "maternité"],
    "Conge paternite": ["paternite", "paternité"],
    "Conge sans solde": ["sans solde"],
}
EXPENSE_TYPE_EVIDENCE = {
    "Hotel": ["hotel", "hebergement", "hébergement", "nuit", "nuitee", "nuitée"],
    "Restaurant": ["restaurant", "repas"],
    "Transport": ["taxi", "bus", "train", "avion", "vol"],
}

NON_LOCATION_NOISE_WORDS = {
    "client",
    "clients",
    "fournisseur",
    "fournisseurs",
    "societe",
    "entreprise",
    "partenaire",
}

BENEFICIARY_BLOCKLIST = {
    "taxi",
    "train",
    "bus",
    "avion",
    "vol",
    "voiture",
    "vehicule",
    "transport",
    "mission",
} | NON_LOCATION_NOISE_WORDS


def normalize_ws(text: Any) -> str:
    return re.sub(r"\s+", " ", str(text or "")).strip()


def norm(text: Any) -> str:
    value = normalize_ws(text).lower()
    value = unicodedata.normalize("NFD", value)
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    value = value.replace("/", " ")
    value = value.replace("-", " ")
    value = re.sub(r"[^a-z0-9+.# ]+", " ", value)
    return normalize_ws(value)


def capitalize_entity(value: Any) -> str:
    parts = normalize_ws(value).split()
    return " ".join(part[:1].upper() + part[1:] for part in parts if part)


def _smart_capitalize_location(value: Any) -> str:
    text = normalize_ws(value)
    if not text:
        return ""

    proper_nouns = {
        "batiment", "building", "tour", "bloc", "zone",
        "etage", "niveau", "floor", "entree", "sortie",
        "parking", "garage", "hall", "salle",
    }
    lower_words = {
        "d", "de", "des", "du", "la", "le", "les", "un", "une",
        "pour", "avec", "nord", "est", "ouest", "sud",
    }
    parts = text.split()
    formatted: list[str] = []

    for index, part in enumerate(parts):
        raw = part.strip()
        token = raw.lower()

        if not raw:
            continue

        if index == 0:
            formatted.append(raw[:1].upper() + raw[1:].lower())
            continue

        if token in proper_nouns:
            formatted.append(raw[:1].upper() + raw[1:].lower())
            continue

        if token in lower_words:
            formatted.append(token)
            continue

        if len(raw) == 1 and raw.isalpha():
            formatted.append(raw.upper())
            continue

        if raw[:1].isdigit():
            formatted.append(raw.lower())
            continue

        if "-" in raw:
            formatted.append("-".join(sub.lower() for sub in raw.split("-") if sub))
            continue

        formatted.append(token)

    return " ".join(formatted).strip()


def _normalize_spatial_phrase(value: Any) -> str:
    text = normalize_ws(value)
    if not text:
        return ""

    text = re.sub(r"\bl\s+([a-zà-ÿ])", r"l'\1", text, flags=re.IGNORECASE)
    text = re.sub(r"\bd\s+([a-zà-ÿ])", r"d'\1", text, flags=re.IGNORECASE)
    text = re.sub(r"\bentrer\b", "entree", text, flags=re.IGNORECASE)
    text = re.sub(r"\bsorti\b", "sortie", text, flags=re.IGNORECASE)
    text = normalize_ws(text).strip(" ,;:-")
    return text


def extract_descriptive_location(text: Any, intent_keyword: str | None = None) -> str | None:
    """
    Extract freeform location descriptions for parking/workspace requests.
    Returns the first spatial description found and stops before justification/date noise.
    """
    source = normalize_ws(text)
    if not source:
        return None

    lowered = norm(source)

    prefix_patterns = [
        r"pres\s+d(?:e)?",
        r"pres\s+d['’]",
        r"a\s+cote\s+de",
        r"proche\s+de",
        r"devant",
        r"derriere",
        r"loin\s+de",
        r"dans",
        r"coin",
        r"zone",
        r"batiment",
    ]

    stop_patterns = [
        r"[,;.]",
        r"\b(?:pour|afin|car|avec|urgent|urgence|immediat|immediatement)\b",
        r"\b\d{4}-\d{2}-\d{2}\b",
        r"\b\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?\b",
        r"\b\d{1,2}(?:er|eme|e)?\s+(?:janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)\b",
        r"\btrop\s+de\b",
    ]

    for prefix_pattern in prefix_patterns:
        match = re.search(rf"\b({prefix_pattern})\b", lowered, flags=re.IGNORECASE)
        if not match:
            continue

        prefix = normalize_ws(match.group(1))
        tail = normalize_ws(lowered[match.end():]).strip(" ,;:-")
        if not tail:
            continue

        cut_points = [len(tail)]
        for stop_pattern in stop_patterns:
            stop_match = re.search(stop_pattern, tail, flags=re.IGNORECASE)
            if stop_match:
                cut_points.append(stop_match.start())

        location = normalize_ws(tail[: min(cut_points)]).strip(" ,;:-")
        if not location:
            continue

        location = re.sub(r"\b(?:ou|et)\b.*$", "", location, flags=re.IGNORECASE)
        location = _normalize_spatial_phrase(location)
        if len(location) < 3:
            continue

        return _smart_capitalize_location(_normalize_spatial_phrase(f"{prefix} {location}"))

    building_pattern = (
        r"\b(batiment|building|tour|bloc)\s+([a-z0-9]{1,3})\b"
        r"(?:\s+(etage|niveau|floor)\s+([a-z0-9]+))?"
    )
    match = re.search(building_pattern, lowered, flags=re.IGNORECASE)
    if match:
        parts = [
            normalize_ws(match.group(1)),
            normalize_ws(match.group(2)).upper(),
        ]
        if match.group(3) and match.group(4):
            parts.append(normalize_ws(match.group(3)))
            parts.append(normalize_ws(match.group(4)))
        return _smart_capitalize_location(_normalize_spatial_phrase(" ".join(part for part in parts if part)))

    return None


def _strip_boundary_tail(text: str) -> str:
    clean = normalize_ws(text)
    if not clean:
        return ""

    lowered = clean.lower()
    cut_points = [len(clean)]

    for marker in STOP_MARKERS:
        match = re.search(rf"\b{re.escape(marker)}\b", lowered)
        if match:
            cut_points.append(match.start())

    match = BOUNDARY_REGEX.search(clean)
    if match:
        cut_points.append(match.start())

    cut = min(cut_points)
    return normalize_ws(clean[:cut]).strip(" ,;:-/\t")


def _protect_date_prefixes(text: str) -> tuple[str, dict[str, str]]:
    protected_phrases = [
        "des le",
        "a partir du",
        "a partir de",
        "des demain",
        "des aujourd hui",
        "jusqu au",
        "jusqu a",
    ]

    protected_text = normalize_ws(text)
    placeholders: dict[str, str] = {}
    for index, phrase in enumerate(protected_phrases):
        token = f"__DATE_PREFIX_{index}__"
        pattern = rf"\b{re.escape(phrase)}\b"
        if re.search(pattern, protected_text, flags=re.IGNORECASE):
            protected_text = re.sub(pattern, token, protected_text, flags=re.IGNORECASE)
            placeholders[token] = phrase

    return protected_text, placeholders


def _restore_date_prefixes(text: str, placeholders: dict[str, str]) -> str:
    restored = text
    for token, phrase in placeholders.items():
        restored = restored.replace(token, phrase)
    return restored


def clean_entity_text(value: Any) -> str:
    text = _strip_boundary_tail(str(value or ""))
    if not text:
        return ""

    text = re.sub(r"\b(?:le|la|les|du|de|des|a|à|au|aux)\b$", "", text, flags=re.IGNORECASE)
    text = re.sub(r"\b\d{4}-\d{2}-\d{2}\b", "", text)
    text = re.sub(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b", "", text)
    text = re.sub(r"\b\d{1,2}[/-]\d{1,2}\b", "", text)
    text = normalize_ws(text).strip(" ,;:-/")
    return text


def is_likely_proper_noun(word: Any, stopwords: set[str] | None = None, month_aliases: dict[str, int] | None = None) -> bool:
    stopwords = set(stopwords or set()) | NON_LOCATION_NOISE_WORDS
    month_aliases = month_aliases or MONTHS
    token = norm(word)
    if not token or token in stopwords or token in month_aliases:
        return False
    if len(token) < 2:
        return False
    if re.fullmatch(r"\d+", token):
        return False
    return True


def _tokenize(text: Any) -> list[str]:
    return [token for token in re.split(r"[^a-z0-9+/.#]+", norm(text)) if token]


def _is_time_sensitive(text: str) -> bool:
    lowered = norm(text)
    return bool(
        re.search(r"\b(aujourd hui|demain|apres demain|ce soir|ce matin|ce mois|cette semaine|prochaine semaine|le\s+\d{1,2}|\b\d{1,2}\s+(?:janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)\b)", lowered)
        or re.search(r"\b\d{1,2}[/-]\d{1,2}(?:[/-]\d{2,4})?\b", lowered)
    )


def _has_explicit_zone_evidence(text: str) -> bool:
    lowered = norm(text)
    if not lowered:
        return False
    return _has_any_phrase(
        lowered,
        [
            "pres de",
            "a cote de",
            "proche de",
            "devant",
            "derriere",
            "loin de",
            "dans",
            "zone",
            "batiment",
            "bloc",
            "tour",
            "entree",
            "sortie",
            "hall",
        ],
    )


def _normalize_schedule_token(token: str) -> str:
    cleaned = normalize_ws(token).lower()
    cleaned = re.sub(r"\s*[-–]\s*", "-", cleaned)
    cleaned = re.sub(r"\s*h\s*", "h", cleaned)
    return cleaned


def _extract_schedule_change(text: str) -> tuple[str, str]:
    source = normalize_ws(text)
    if not source:
        return "", ""

    range_pattern = r"\d{1,2}\s*h\s*(?:[-–]\s*\d{1,2}\s*h)?"
    direct_patterns = [
        rf"\bpasser\s+de\s*({range_pattern})\s*(?:a|à|vers)\s*({range_pattern})\b",
        rf"\bchangement\s+d\s*horaire.*?\bde\s*({range_pattern})\s*(?:a|à|vers)\s*({range_pattern})\b",
    ]
    for pattern in direct_patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if match:
            return _normalize_schedule_token(match.group(1)), _normalize_schedule_token(match.group(2))

    if re.search(r"\b(changement\s+d\s*horaire|horaire|passer)\b", source, flags=re.IGNORECASE):
        ranges = re.findall(range_pattern, source, flags=re.IGNORECASE)
        if len(ranges) >= 2:
            return _normalize_schedule_token(ranges[0]), _normalize_schedule_token(ranges[1])

    return "", ""


class BoundaryAwareExtractor:
    def extract_location_pair(self, text: str) -> tuple[str, str]:
        source = normalize_ws(text)
        if not source:
            return "", ""

        stop_markers = (
            "uniquement",
            "seulement",
            "exclusivement",
            "en",
            "pour",
            "afin",
            "car",
            "avec",
            "le",
            "la",
            "les",
            "un",
            "une",
            "du",
            "de",
            "des",
        )
        stop_pattern = "|".join(map(re.escape, stop_markers))
        destination_span = re.compile(
            rf"^\s*([a-zà-ÿ]{{2,}}(?:\s+[a-zà-ÿ]{{2,}})?)"
            rf"(?=\s+(?:{stop_pattern})\b|\s+\d|$)",
            flags=re.IGNORECASE,
        )

        vers_matches = list(re.finditer(r"\bvers\b", source, flags=re.IGNORECASE))
        for match in vers_matches:
            left = source[:match.start()]
            right = source[match.end():]
            left_markers = list(re.finditer(r"\b(?:de|du|depuis)\b", left, flags=re.IGNORECASE))
            if not left_markers:
                continue

            depart_raw = left[left_markers[-1].end():]
            depart = self._clean_location(depart_raw)
            right_match = destination_span.search(right)
            destination_raw = right_match.group(1) if right_match else right
            destination = self._clean_location(destination_raw, destination=True)
            if depart and destination and norm(depart) != norm(destination):
                return capitalize_entity(depart), capitalize_entity(destination)

        route_patterns = [
            rf"\bdepart\s*[:\-]?\s*(.+?)\s+(?:destination|arrivee|arrivée)\s*[:\-]?\s*(.+?)"
            rf"(?=\s+(?:{stop_pattern}|debut|début|vers|a partir|depuis|jusqu|et|ou|qui|que)\b|\s+\d|\b\d{{4}}\b|\b\d{{1,2}}[/-]\d{{1,2}}\b|$)",
        ]

        for pattern in route_patterns:
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if not match:
                continue
            depart = self._clean_location(match.group(1))
            destination = self._clean_location(match.group(2), destination=True)
            if depart and destination and norm(depart) != norm(destination):
                return capitalize_entity(depart), capitalize_entity(destination)

        return "", ""

        return "", ""

    def extract_subject_name(self, text: str, keyword: str) -> str:
        source = normalize_ws(text)
        if not source or not keyword.strip():
            return ""

        keyword_pattern = re.escape(keyword.strip())
        stop_pattern = "|".join(map(re.escape, STOP_MARKERS))
        generic_stop_tail = r"\s+(?:des|le|du|de|vers|pour|afin|a partir|depuis|jusqu|a|à|dans|qui|que|debut|debute|commence)\b"

        if norm(keyword) == "formation":
            qualifier_pattern = r"[a-zA-ZÀ-ÿ]+"
            subject_pattern = r"[a-zA-ZÀ-ÿ0-9\s\-\./+#]+?"
            route_depart, route_destination = self.extract_location_pair(source)
            route_locations = [normalize_ws(route_depart), normalize_ws(route_destination)]

            def trim_route_suffix(candidate: str) -> str:
                cleaned_candidate = normalize_ws(candidate)
                if not cleaned_candidate:
                    return ""
                for location in route_locations:
                    if not location:
                        continue
                    if re.search(rf"\b(?:de|du)\s+{re.escape(location)}$", cleaned_candidate, flags=re.IGNORECASE):
                        cleaned_candidate = re.sub(
                            rf"\s+(?:de|du)\s+{re.escape(location)}$",
                            "",
                            cleaned_candidate,
                            flags=re.IGNORECASE,
                        ).strip()
                return normalize_ws(cleaned_candidate)

            formation_patterns = [
                (
                    rf"\b{keyword_pattern}\b\s+({qualifier_pattern})\s+de\s+({subject_pattern})"
                    rf"(?=\s+(?:de|vers|le|du|pour)\b|\s+\d|$)",
                    "de",
                ),
                (
                    rf"\b{keyword_pattern}\b\s+({qualifier_pattern})\s+en\s+({subject_pattern})"
                    rf"(?=\s+(?:de|vers|le|du|pour)\b|\s+\d|$)",
                    "en",
                ),
                (
                    rf"\b{keyword_pattern}\b\s+({qualifier_pattern})\s+sur\s+({subject_pattern})"
                    rf"(?=\s+(?:de|vers|le|du|pour)\b|\s+\d|$)",
                    "sur",
                ),
            ]

            for pattern, connector in formation_patterns:
                match = re.search(pattern, source, flags=re.IGNORECASE)
                if not match:
                    continue

                qualifier = self._clean_subject(match.group(1))
                subject = self._clean_subject(match.group(2))
                if qualifier and subject:
                    candidate = f"{capitalize_entity(qualifier)} {connector} {capitalize_entity(subject)}"
                    candidate = trim_route_suffix(candidate)
                    if candidate:
                        return capitalize_entity(candidate)

            direct_subject_match = re.search(
                rf"\b{keyword_pattern}\b\s+de\s+({subject_pattern})(?={generic_stop_tail}|\s+\d|$)",
                source,
                flags=re.IGNORECASE,
            )
            if direct_subject_match:
                direct_subject = self._clean_subject(direct_subject_match.group(1))
                direct_subject = trim_route_suffix(direct_subject)
                if direct_subject:
                    return capitalize_entity(direct_subject)

            qualifier_subject_match = re.search(
                rf"\b{keyword_pattern}\b\s+({qualifier_pattern})\s+([a-zA-ZÀ-ÿ0-9][a-zA-ZÀ-ÿ0-9\s\-\./+#]*?)"
                rf"(?={generic_stop_tail}|\s+\d|$)",
                source,
                flags=re.IGNORECASE,
            )
            if qualifier_subject_match:
                qualifier = self._clean_subject(qualifier_subject_match.group(1))
                subject = self._clean_subject(qualifier_subject_match.group(2))
                if qualifier and subject:
                    candidate = capitalize_entity(f"{qualifier} {subject}")
                    candidate = trim_route_suffix(candidate)
                    if candidate:
                        return capitalize_entity(candidate)

        patterns = [
            rf"\b{keyword_pattern}\b(?:\s+(?:en|sur|de|d['’]))?\s+([a-z][a-z0-9\s\-\./+#]*?)(?={generic_stop_tail}|\s+\d|\b\d{{4}}\b|$)",
            rf"\bpour\s+(?:une?\s+)?{keyword_pattern}\b(?:\s+(?:en|sur|de|d['’]))?\s+([a-z][a-z0-9\s\-\./+#]*?)(?={generic_stop_tail}|\s+\d|\b\d{{4}}\b|$)",
        ]

        for pattern in patterns:
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if not match:
                continue
            candidate = self._clean_subject(match.group(1))
            candidate = trim_route_suffix(candidate) if norm(keyword) == "formation" else candidate
            if candidate:
                return capitalize_entity(candidate)

        return ""

    def extract_date(self, text: str) -> str:
        start, _ = self.extract_date_range(text)
        return start

    def extract_date_range(self, text: str) -> tuple[str, str]:
        source = normalize_ws(text)
        if not source:
            return "", ""

        protected_source, placeholders = _protect_date_prefixes(source)
        lowered = norm(source)
        today = datetime.now().date()
        extracted: list[str] = []

        duration_start, duration_end = _extract_relative_duration_dates(source)
        if duration_start:
            extracted.append(duration_start)
            if duration_end:
                extracted.append(duration_end)

        if re.search(r"\b(?:aujourd['’ ]hui|ce\s+jour)\b", lowered, flags=re.IGNORECASE):
            extracted.append(today.isoformat())
        if re.search(r"\b(?:demain|des\s+demain)\b", lowered, flags=re.IGNORECASE):
            extracted.append((today + timedelta(days=1)).isoformat())
        if re.search(r"\b(?:apres\s+demain|apr[eè]s\s+demain)\b", lowered, flags=re.IGNORECASE):
            extracted.append((today + timedelta(days=2)).isoformat())

        relative_day_match = re.search(r"\b(?:dans|d['’]ici|sous)\s+(\d+|un|une)\s+jours?\b", lowered, flags=re.IGNORECASE)
        if relative_day_match:
            raw_days = relative_day_match.group(1)
            days = 1 if raw_days in {"un", "une"} else int(raw_days)
            extracted.append((today + timedelta(days=days)).isoformat())

        relative_month_match = re.search(r"\b(?:dans|d['’]ici|sous)\s+(\d+|un|une)\s+mois\b", lowered, flags=re.IGNORECASE)
        if relative_month_match:
            raw_months = relative_month_match.group(1)
            months = 1 if raw_months in {"un", "une"} else int(raw_months)
            extracted.append(_shift_date_by_months(today, months).isoformat())

        months_later_match = re.search(r"\b(\d+|un|une)?\s*mois\s+(?:plus\s+tard|apres|apr[eè]s|plus\s+loin)\b", lowered, flags=re.IGNORECASE)
        if months_later_match:
            raw_months = months_later_match.group(1)
            months = 1 if not raw_months or raw_months in {"un", "une"} else int(raw_months)
            extracted.append(_shift_date_by_months(today, months).isoformat())

        compact_range = re.search(
            r"\bdu\s+(\d{1,2})\s+au\s+(\d{1,2})\s+(janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)\s+(\d{4})\b",
            norm(source),
            flags=re.IGNORECASE,
        )
        if compact_range:
            year = int(compact_range.group(4))
            first = self._parse_date_token(f"{compact_range.group(1)} {compact_range.group(3)} {year}")
            second = self._parse_date_token(f"{compact_range.group(2)} {compact_range.group(3)} {year}")
            if first:
                extracted.extend([first, second] if second else [first])

        compact_range_without_year = re.search(
            r"\b(?:du\s+)?(\d{1,2})\s+au\s+(\d{1,2})\s+(janvier|fevrier|fÃ©vrier|mars|avril|mai|juin|juillet|aout|aoÃ»t|septembre|octobre|novembre|decembre|dÃ©cembre)\b",
            norm(source),
            flags=re.IGNORECASE,
        )
        if compact_range_without_year:
            year = datetime.now().year
            first = self._parse_date_token(f"{compact_range_without_year.group(1)} {compact_range_without_year.group(3)} {year}")
            second = self._parse_date_token(f"{compact_range_without_year.group(2)} {compact_range_without_year.group(3)} {year}")
            if first:
                extracted.extend([first, second] if second else [first])

        patterns = [
            r"\bdu\s+(\d{1,2}(?:er)?[/-]\d{1,2}(?:[/-]\d{2,4})?)\s+au\s+(\d{1,2}(?:er)?[/-]\d{1,2}(?:[/-]\d{2,4})?)",
            r"\b(?:du|a partir du|à partir du|depuis le|le|des le|des demain|des aujourd hui|a partir de|à partir de)\s+(\d{1,2}(?:er)?\s+(?:janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)(?:\s+\d{4})?)",
            r"\b(?:du|de|le|des le|a partir du|à partir du|a partir de|des demain|des aujourd hui)\s+(\d{1,2}(?:er)?[/-]\d{1,2}(?:[/-]\d{2,4})?)",
            r"\b(\d{1,2}(?:er)?\s+(?:janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)(?:\s+\d{4})?)",
            r"\b(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})\b",
        ]

        for pattern in patterns:
            match = re.search(pattern, protected_source, flags=re.IGNORECASE)
            if not match:
                continue
            first = self._parse_date_token(_restore_date_prefixes(match.group(1), placeholders))
            if first:
                tail = protected_source[match.end():]
                next_match = re.search(r"\b(?:au|jusqu(?:'|e)?\s+a|a|à)\s+(\d{1,2}(?:er)?[/-]\d{1,2}(?:[/-]\d{2,4})?|\d{1,2}(?:er)?\s+(?:janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)(?:\s+\d{4})?)", tail, flags=re.IGNORECASE)
                second = self._parse_date_token(_restore_date_prefixes(next_match.group(1), placeholders)) if next_match else ""
                extracted.extend([first, second] if second else [first])

        fallback = self._extract_first_calendar_date(_restore_date_prefixes(protected_source, placeholders))
        if fallback:
            extracted.append(fallback)

        if not extracted:
            return "", ""

        unique = sorted(set(extracted))
        return unique[0], (unique[1] if len(unique) > 1 else "")

    def extract_amount(self, text: str) -> str:
        source = normalize_ws(text)
        if not source:
            return ""

        patterns = [
            r"\b(\d+(?:[.,]\d{1,2})?)\s*(?:dt|tnd|dinar|dinars|euro|euros|usd|dollars?)\b",
            r"\b(?:dt|tnd|dinar|dinars|euro|euros|usd|dollars?)\s*(\d+(?:[.,]\d{1,2})?)\b",
        ]
        for pattern in patterns:
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if match:
                return normalize_ws(match.group(1)).replace(",", ".")
        return ""

    def extract_all_fields(self, text: str, intents: Iterable[tuple[str, float]] | None = None) -> dict[str, str]:
        source = normalize_ws(text)
        lowered = norm(source)
        intent_names = {name for name, confidence in (intents or []) if confidence >= 0.25}
        details: dict[str, str] = {}

        if not source:
            return details

        priority = self._suggest_priority(source)
        details["niveauUrgenceAutre"] = priority
        details["descriptionBesoin"] = source

        depart, destination = self.extract_location_pair(source)
        if depart or destination:
            details["ai_lieu_depart_actuel"] = depart
            details["ai_lieu_souhaite"] = destination

        formation_name = self.extract_subject_name(source, "formation")
        certification_name = self.extract_subject_name(source, "certification")
        if certification_name and not formation_name:
            formation_name = certification_name
        if formation_name:
            details["ai_nom_formation"] = formation_name

        if re.search(r"\bcertification\b", lowered):
            details["ai_type_formation"] = "Certification"
        elif formation_name:
            details["ai_type_formation"] = "Formation externe"

        date_start, date_end = self.extract_date_range(source)
        if date_start:
            details["dateSouhaiteeAutre"] = date_start
            details["ai_date_souhaitee_metier"] = date_start
        if date_end:
            details["ai_date_fin_metier"] = date_end

        amount = self.extract_amount(source)
        if amount:
            details["ai_montant"] = amount

        if self._contains_any(lowered, ["acces", "badge", "vpn", "systeme", "logiciel", "application"]):
            systeme = self.extract_subject_name(source, "acces") or self._extract_after_any(source, ["vpn", "badge", "logiciel", "application", "systeme"])
            if systeme:
                details["ai_systeme_concerne"] = systeme

        if self._contains_any(lowered, ["salle", "reunion", "meeting", "conference"]):
            salle = self._extract_after_any(source, ["salle", "reunion", "meeting", "conference"])
            if salle:
                details["ai_salle_souhaitee"] = salle
            if date_start:
                details["ai_date_reservation"] = date_start
            duration = self._extract_duration(source)
            if duration:
                details["ai_duree"] = duration

        if self._contains_any(lowered, ["parking", "stationnement"]):
            zone = extract_descriptive_location(source, "parking") or self._extract_after_any(source, ["parking", "stationnement"])
            if zone:
                details["ai_zone_souhaitee"] = zone

        if self._contains_any(lowered, ["transport", "deplacement", "trajet", "mission"]):
            if depart:
                details["ai_lieu_depart_actuel"] = depart
            if destination:
                details["ai_lieu_souhaite"] = destination
            transport_type = self._infer_transport_type(source)
            if transport_type:
                details["ai_type_transport"] = transport_type

        if _has_leave_request_evidence(source):
            conge_type = self._infer_leave_type(source)
            if conge_type:
                details["ai_type_conge"] = conge_type
            if date_start:
                details["ai_date_debut_conge"] = date_start
            if date_end:
                details["ai_date_fin_conge"] = date_end

        expense_type = _detect_expense_type_from_text(source)
        if expense_type:
            details["ai_type_depense"] = expense_type

        if self._contains_any(lowered, ["materiel", "equipement", "chaise", "ecran", "clavier", "souris"]):
            material = self._extract_after_any(source, ["materiel", "matériel", "equipement", "équipement", "chaise", "ecran", "écran", "clavier", "souris"])
            if material:
                details["ai_materiel_concerne"] = material

        if "descriptionBesoin" not in details:
            details["descriptionBesoin"] = source

        if "niveauUrgenceAutre" not in details:
            details["niveauUrgenceAutre"] = priority

        if intent_names and not any(key.startswith("ai_") for key in details):
            details["ai_contexte_general"] = source

        return details

    def _clean_location(self, value: Any, destination: bool = False) -> str:
        candidate = clean_entity_text(value)
        candidate = re.sub(r"\b(?:pour|afin|car|avec|des|le|du|de|vers|a partir|depuis|jusqu|et|ou|qui|que)\b.*$", "", candidate, flags=re.IGNORECASE)
        if destination:
            candidate = re.sub(r"\b(?:uniquement|seulement|exclusivement|en|des|debut|début|pour|afin|car|avec|le|la|les|un|une|du|de)\b.*$", "", candidate, flags=re.IGNORECASE)
            candidate = re.sub(r"\s+(?:en|uniquement|seulement|exclusivement)$", "", candidate, flags=re.IGNORECASE)
        candidate = normalize_ws(candidate)
        words = candidate.split()
        if len(words) > 3:
            candidate = " ".join(words[:3])
        if not candidate:
            return ""
        if any(re.search(r"\d", token) for token in words):
            return ""
        if not all(is_likely_proper_noun(word) for word in words):
            return ""
        return candidate

    def _clean_subject(self, value: Any) -> str:
        candidate = clean_entity_text(value)
        candidate = re.sub(r"\b(?:pour|afin|car|avec|des|le|du|de|vers|a partir|depuis|jusqu|et|ou|qui|que)\b.*$", "", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"\b(?:qui|que|dont)\b.*$", "", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"\s+(?:a|à|au|aux|dans)\s+(?!distance\b|distanciel\b|domicile\b)[a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2}(?=\s+(?:qui|que|le|du|de|pour|afin|car|avec|des|a partir|depuis|jusqu|debut|debute|commence)\b|$)", "", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"\b\d{4}\b.*$", "", candidate)
        candidate = re.sub(r"\b\d{1,2}[/-]\d{1,2}\b.*$", "", candidate)
        candidate = normalize_ws(candidate)
        if not candidate:
            return ""
        words = candidate.split()
        if len(words) > 5:
            candidate = " ".join(words[:5])
        return candidate

    def _extract_after_any(self, text: str, keywords: Iterable[str]) -> str:
        source = normalize_ws(text)
        stop_pattern = "|".join(map(re.escape, STOP_MARKERS))
        for keyword in keywords:
            pattern = rf"\b{re.escape(keyword)}\b(?:\s+(?:de|du|des|sur|en|pour|a|à|d['’]))?\s+(.+?)(?=\s+(?:{stop_pattern})\b|\b\d{{4}}\b|\b\d{{1,2}}[/-]\d{{1,2}}\b|$)"
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if match:
                candidate = self._clean_subject(match.group(1))
                if candidate:
                    return capitalize_entity(candidate)
        return ""

    def _extract_duration(self, text: str) -> str:
        source = normalize_ws(text)
        if not source:
            return ""
        match = re.search(r"\b(\d+\s*(?:jour|jours|heure|heures|semaine|semaines|mois))\b", source, flags=re.IGNORECASE)
        if match:
            return normalize_ws(match.group(1))
        return ""

    def _infer_transport_type(self, text: str) -> str:
        lowered = norm(text)
        if self._contains_any(lowered, ["taxi"]):
            return "Taxi"
        if self._contains_any(lowered, ["train"]):
            return "Train"
        if self._contains_any(lowered, ["bus", "autocar"]):
            return "Bus"
        if self._contains_any(lowered, ["voiture", "vehicule", "véhicule", "service"]):
            return "Voiture de service"
        return ""

    def _infer_leave_type(self, text: str) -> str:
        lowered = norm(text)
        if not _has_leave_request_evidence(text):
            return ""
        if self._contains_any(lowered, ["maladie", "medical", "médical", "hospitalisation"]):
            return "Conge maladie"
        if self._contains_any(lowered, ["maternite", "maternité"]):
            return "Conge maternite"
        if self._contains_any(lowered, ["paternite", "paternité"]):
            return "Conge paternite"
        if self._contains_any(lowered, ["sans solde"]):
            return "Conge sans solde"
        return "Conge annuel"

    def _contains_any(self, text: str, keywords: Iterable[str]) -> bool:
        haystack = norm(text)
        return _has_any_phrase(haystack, [norm(keyword) for keyword in keywords])

    def _suggest_priority(self, text: str) -> str:
        lowered = norm(text)
        if self._contains_any(lowered, ["urgent", "urgence", "des aujourd hui", "immediatement", "immédiatement", "medical", "médical", "hospitalisation", "accident"]):
            return "Tres urgente" if self._contains_any(lowered, ["immediatement", "immédiatement", "hospitalisation", "accident"]) else "Urgente"
        if self._contains_any(lowered, ["des que possible", "bientot", "bientôt"]):
            return "Normale"
        if self._contains_any(lowered, ["pas urgent", "quand possible", "confort"]):
            return "Faible"
        if self._contains_any(lowered, ["formation", "certification"]):
            return "Normale"
        return "Normale"

    def _parse_date_token(self, token: str) -> str:
        text = normalize_ws(token)
        if not text:
            return ""
        text = re.sub(r"\b(?:le|du|de|au|a|à|des|depuis)\b", "", text, flags=re.IGNORECASE)
        text = normalize_ws(text)

        numeric_match = re.fullmatch(r"(\d{1,2})(?:er)?[/-](\d{1,2})(?:[/-](\d{2,4}))?", text)
        if numeric_match:
            day = int(numeric_match.group(1))
            month = int(numeric_match.group(2))
            year = int(numeric_match.group(3) or datetime.now().year)
            if year < 100:
                year += 2000
            if 1 <= month <= 12 and 1 <= day <= 31:
                try:
                    return datetime(year, month, day).date().isoformat()
                except ValueError:
                    return ""

        text = norm(text)
        match = re.fullmatch(r"(\d{1,2})(?:er)?\s+([a-z]+)(?:\s+(\d{4}))?", text)
        if not match:
            return ""

        day = int(match.group(1))
        month_name = match.group(2)
        year = int(match.group(3) or datetime.now().year)
        month = MONTHS.get(month_name, 0)
        if not month:
            return ""
        if year < 100:
            year += 2000
        try:
            candidate = datetime(year, month, day).date()
        except ValueError:
            return ""
        if match.group(3) is None:
            today = datetime.now().date()
            if candidate < today:
                try:
                    candidate = datetime(year + 1, month, day).date()
                except ValueError:
                    return ""
        return candidate.isoformat()

    def _extract_first_calendar_date(self, text: str) -> str:
        source = normalize_ws(text)
        numeric_patterns = [
            r"\b(\d{1,2}[/-]\d{1,2}[/-]\d{2,4})\b",
            r"\b(\d{1,2}(?:er)?[/-]\d{1,2})\b",
        ]
        for pattern in numeric_patterns:
            match = re.search(pattern, source)
            if match:
                parsed = self._parse_date_token(match.group(1))
                if parsed:
                    return parsed

        match = re.search(r"\b(\d{1,2}(?:er)?\s+(?:janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)(?:\s+\d{4})?)\b", source, flags=re.IGNORECASE)
        if match:
            return self._parse_date_token(match.group(1))
        return ""


class PatternMiner:
    def __init__(self) -> None:
        self.version = "2.0"
        self.trained_at = ""
        self.total_samples = 0
        self.value_counts: dict[str, Counter[str]] = defaultdict(Counter)
        self.context_pairs: dict[str, list[list[str]]] = defaultdict(list)
        self.priority_signals: dict[str, list[str]] = {label: list(signals) for label, signals in PRIORITY_SIGNALS.items()}
        self.field_cooccurrence: dict[str, list[str]] = {}
        self._field_cooccurrence_counts: dict[str, Counter[str]] = defaultdict(Counter)
        self._field_type_counts: dict[str, Counter[str]] = defaultdict(Counter)

    def fit(self, feedback_samples: Iterable[dict[str, Any]]) -> "PatternMiner":
        self.total_samples = 0
        self.trained_at = datetime.now().isoformat(timespec="seconds")
        self.value_counts = defaultdict(Counter)
        self.context_pairs = defaultdict(list)
        self._field_cooccurrence_counts = defaultdict(Counter)
        self._field_type_counts = defaultdict(Counter)

        for sample in feedback_samples or []:
            if not isinstance(sample, dict):
                continue

            prompt = normalize_ws(
                sample.get("prompt")
                or sample.get("text")
                or sample.get("rawText")
                or sample.get("titre")
                or ""
            )
            title = normalize_ws(sample.get("titre") or sample.get("title") or "")
            description = normalize_ws(sample.get("description") or "")
            general = sample.get("general") if isinstance(sample.get("general"), dict) else {}
            if not prompt:
                prompt = normalize_ws(" ".join(part for part in [title, description, normalize_ws(general.get("titre")), normalize_ws(general.get("description"))] if part))

            details = sample.get("details")
            if isinstance(details, dict):
                detail_items = [{"fieldKey": key, "fieldValue": value, "fieldType": "text"} for key, value in details.items()]
            elif isinstance(details, list):
                detail_items = [item for item in details if isinstance(item, dict)]
            else:
                detail_items = []

            if not prompt and not detail_items:
                continue

            self.total_samples += 1
            seen_fields: list[str] = []

            for item in detail_items:
                field_key = normalize_ws(item.get("fieldKey") or item.get("key") or item.get("name") or "")
                field_value = normalize_ws(item.get("fieldValue") or item.get("value") or "")
                field_type = normalize_ws(item.get("fieldType") or item.get("type") or "text")
                if not field_key or not field_value:
                    continue

                normalized_value = norm(field_value)
                if not normalized_value:
                    continue

                self.value_counts[field_key][normalized_value] += 1
                self._field_type_counts[field_key][field_type or "text"] += 1
                seen_fields.append(field_key)

                context = self._build_context_fragment(prompt, field_value)
                self.context_pairs[field_key].append([context, normalized_value])

            for left in seen_fields:
                for right in seen_fields:
                    if left == right:
                        continue
                    self._field_cooccurrence_counts[left][right] += 1

            priority = normalize_ws(sample.get("priorite") or general.get("priorite") or sample.get("priority") or "").upper()
            if priority in self.priority_signals:
                tokens = _tokenize(prompt or description or title)
                for token in tokens:
                    self.priority_signals[priority].append(token)

        self.field_cooccurrence = {
            key: [related for related, _ in counts.most_common(6)]
            for key, counts in self._field_cooccurrence_counts.items()
        }
        self.priority_signals = {
            label: self._compress_signals(signals)
            for label, signals in self.priority_signals.items()
        }
        return self

    def predict_field(self, field_key: str, prompt: str) -> tuple[str, float]:
        field_key = normalize_ws(field_key)
        if not field_key:
            return "", 0.0

        prompt_text = normalize_ws(prompt)
        if not prompt_text:
            return "", 0.0

        learned = self.value_counts.get(field_key)
        if not learned:
            return "", 0.0

        prompt_norm = norm(prompt_text)
        prompt_tokens = set(_tokenize(prompt_text))
        total = sum(learned.values()) or 1
        best_value = ""
        best_score = 0.0

        for value, count in learned.items():
            if not value:
                continue

            score = count / total
            if _has_phrase(prompt_norm, value):
                score += 0.65

            value_tokens = set(value.split())
            if value_tokens:
                overlap = len(prompt_tokens & value_tokens) / len(value_tokens)
                score += overlap * 0.45

            for context, stored_value in self.context_pairs.get(field_key, []):
                if stored_value != value:
                    continue
                score += self._context_similarity(prompt_norm, context) * 0.5

            if score > best_score:
                best_score = score
                best_value = value

        if not best_value:
            return "", 0.0

        confidence = max(0.0, min(0.99, best_score))
        return capitalize_entity(best_value), round(confidence, 3)

    def suggest_priority(self, prompt: str) -> tuple[str, float]:
        prompt_text = normalize_ws(prompt)
        if not prompt_text:
            return "NORMALE", 0.35

        prompt_norm = norm(prompt_text)
        scores = {"HAUTE": 0.0, "NORMALE": 0.0, "BASSE": 0.0}

        for label, signals in self.priority_signals.items():
            for signal in signals:
                signal_norm = norm(signal)
                if not signal_norm:
                    continue
                if _has_phrase(prompt_norm, signal_norm):
                    scores[label] += 1.0
                    if len(signal_norm.split()) > 1:
                        scores[label] += 0.35

        if re.search(r"\b(urgent|urgence|bloquant|immediatement|immédiatement|medical|médical|hospitalisation|accident)\b", prompt_norm, flags=re.IGNORECASE):
            scores["HAUTE"] += 2.0
        if re.search(r"\b(des que possible|bientot|bientôt)\b", prompt_norm, flags=re.IGNORECASE):
            scores["NORMALE"] += 1.0
        if re.search(r"\b(quand possible|pas urgent|confort)\b", prompt_norm, flags=re.IGNORECASE):
            scores["BASSE"] += 2.0
        if re.search(r"\b(formation|certification)\b", prompt_norm, flags=re.IGNORECASE):
            scores["NORMALE"] += 0.5

        best_label = max(scores, key=scores.get)
        total = sum(scores.values()) or 1.0
        confidence = scores[best_label] / total if total else 0.35
        if confidence <= 0:
            confidence = 0.35
        return best_label, round(min(0.99, confidence), 3)

    def to_dict(self) -> dict[str, Any]:
        return {
            "version": self.version,
            "trained_at": self.trained_at or datetime.now().isoformat(timespec="seconds"),
            "total_samples": self.total_samples,
            "value_counts": {key: dict(counter) for key, counter in self.value_counts.items()},
            "context_pairs": {key: value for key, value in self.context_pairs.items()},
            "priority_signals": self.priority_signals,
            "field_cooccurrence": self.field_cooccurrence,
        }

    @classmethod
    def from_dict(cls, payload: dict[str, Any]) -> "PatternMiner":
        miner = cls()
        if not isinstance(payload, dict):
            return miner

        miner.version = str(payload.get("version", "2.0"))
        miner.trained_at = str(payload.get("trained_at", ""))
        miner.total_samples = int(payload.get("total_samples", 0) or 0)

        value_counts = payload.get("value_counts") if isinstance(payload.get("value_counts"), dict) else {}
        for field_key, values in value_counts.items():
            if isinstance(values, dict):
                miner.value_counts[str(field_key)] = Counter({str(value): int(count or 0) for value, count in values.items() if str(value)})

        context_pairs = payload.get("context_pairs") if isinstance(payload.get("context_pairs"), dict) else {}
        for field_key, pairs in context_pairs.items():
            if not isinstance(pairs, list):
                continue
            normalized_pairs: list[list[str]] = []
            for pair in pairs:
                if not isinstance(pair, (list, tuple)) or len(pair) < 2:
                    continue
                normalized_pairs.append([normalize_ws(pair[0]), normalize_ws(pair[1])])
            miner.context_pairs[str(field_key)] = normalized_pairs

        priority_signals = payload.get("priority_signals") if isinstance(payload.get("priority_signals"), dict) else {}
        if priority_signals:
            miner.priority_signals = {str(label): [normalize_ws(signal) for signal in signals if normalize_ws(signal)] for label, signals in priority_signals.items() if isinstance(signals, list)}

        field_cooccurrence = payload.get("field_cooccurrence") if isinstance(payload.get("field_cooccurrence"), dict) else {}
        miner.field_cooccurrence = {str(field_key): [normalize_ws(item) for item in values if normalize_ws(item)] for field_key, values in field_cooccurrence.items() if isinstance(values, list)}
        return miner

    def save(self, path: str | Path) -> None:
        destination = Path(path)
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(json.dumps(self.to_dict(), ensure_ascii=False, indent=2), encoding="utf-8")

    @classmethod
    def load(cls, path: str | Path) -> "PatternMiner":
        source = Path(path)
        if not source.exists():
            return cls()
        try:
            payload = json.loads(source.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return cls()
        return cls.from_dict(payload)

    def all_field_keys(self) -> list[str]:
        return sorted(self.value_counts.keys())

    def rank_candidate_fields(self, prompt: str, limit: int = 6) -> list[tuple[str, str, float]]:
        prompt_text = normalize_ws(prompt)
        if not prompt_text:
            return []

        candidates: list[tuple[str, str, float]] = []
        for field_key in self.all_field_keys():
            value, confidence = self.predict_field(field_key, prompt_text)
            if value and confidence > 0:
                candidates.append((field_key, value, confidence))
        candidates.sort(key=lambda item: item[2], reverse=True)
        return candidates[:limit]

    def _build_context_fragment(self, prompt: str, field_value: str, window: int = 8) -> str:
        if not prompt:
            return normalize_ws(field_value)
        prompt_norm = norm(prompt)
        value_norm = norm(field_value)
        if not value_norm:
            return prompt[:120]
        index = prompt_norm.find(value_norm)
        if index < 0:
            return prompt[:140]
        tokens = prompt.split()
        value_tokens = field_value.split()
        if not tokens or not value_tokens:
            return prompt[:140]
        lower_prompt = [norm(token) for token in tokens]
        lower_value = [norm(token) for token in value_tokens]
        for start in range(len(lower_prompt)):
            if lower_prompt[start:start + len(lower_value)] == lower_value:
                left = max(0, start - window)
                right = min(len(tokens), start + len(lower_value) + window)
                return normalize_ws(" ".join(tokens[left:right]))
        return prompt[:140]

    def _context_similarity(self, prompt_norm: str, context_fragment: str) -> float:
        context_norm = norm(context_fragment)
        if not prompt_norm or not context_norm:
            return 0.0
        prompt_tokens = set(prompt_norm.split())
        context_tokens = set(context_norm.split())
        if not prompt_tokens or not context_tokens:
            return 0.0
        overlap = len(prompt_tokens & context_tokens)
        union = len(prompt_tokens | context_tokens) or 1
        return overlap / union

    def _compress_signals(self, signals: Iterable[str]) -> list[str]:
        counts = Counter(normalize_ws(signal).lower() for signal in signals if normalize_ws(signal))
        return [signal for signal, _ in counts.most_common(40)]


@dataclass
class DynamicFieldCandidate:
    key: str
    label: str
    type: str
    required: bool
    value: str = ""
    options: list[str] | None = None
    confidence: float = 0.0
    source: str = "inferred"

    def to_dict(self) -> dict[str, Any]:
        payload = {
            "key": self.key,
            "label": self.label,
            "type": self.type,
            "required": self.required,
            "value": self.value,
            "source": self.source,
        }
        if self.options:
            payload["options"] = list(self.options)
        return payload


FIELD_BLUEPRINTS = {
    "transport": [
        {"key": "ai_lieu_depart_actuel", "label": "Lieu de depart actuel", "type": "text", "required": True},
        {"key": "ai_lieu_souhaite", "label": "Lieu souhaite", "type": "text", "required": True},
        {"key": "ai_type_transport", "label": "Type de transport", "type": "select", "required": False, "options": ["Taxi", "Train", "Bus", "Voiture de service", "Autre"]},
        {"key": "ai_date_souhaitee_metier", "label": "Date souhaitee", "type": "date", "required": False},
    ],
    "formation": [
        {"key": "ai_nom_formation", "label": "Nom de la formation", "type": "text", "required": True},
        {"key": "ai_type_formation", "label": "Type de formation", "type": "select", "required": True, "options": ["Formation interne", "Formation externe", "Certification", "Autre"]},
        {"key": "ai_date_souhaitee_metier", "label": "Date souhaitee", "type": "date", "required": False},
        {"key": "ai_organisme", "label": "Organisme", "type": "text", "required": False},
    ],
    "finance": [
        {"key": "ai_montant", "label": "Montant", "type": "number", "required": True},
        {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": True},
        {"key": "ai_date_souhaitee_metier", "label": "Date souhaitee", "type": "date", "required": False},
        {"key": "ai_type_depense", "label": "Type de depense", "type": "text", "required": False},
    ],
    "access": [
        {"key": "ai_systeme_concerne", "label": "Systeme concerne", "type": "text", "required": True},
        {"key": "ai_type_acces", "label": "Type d acces", "type": "select", "required": True, "options": ["Lecture seule", "Lecture/Ecriture", "Administrateur", "Autre"]},
        {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": False},
    ],
    "room": [
        {"key": "ai_salle_souhaitee", "label": "Salle souhaitee", "type": "text", "required": True},
        {"key": "ai_date_reservation", "label": "Date de reservation", "type": "date", "required": True},
        {"key": "ai_duree", "label": "Duree", "type": "text", "required": False},
    ],
    "parking": [
        {"key": "ai_zone_souhaitee", "label": "Zone souhaitee", "type": "text", "required": True},
        {"key": "ai_type_stationnement", "label": "Type de stationnement", "type": "select", "required": True, "options": ["Place reservee", "Acces parking", "Autorisation temporaire", "Autre"]},
    ],
    "contract": [
        {"key": "ai_type_contrat", "label": "Type de contrat", "type": "text", "required": True},
        {"key": "ai_date_souhaitee_metier", "label": "Date souhaitee", "type": "date", "required": False},
        {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": False},
    ],
    "career": [
        {"key": "ai_poste_souhaite", "label": "Poste souhaite", "type": "text", "required": True},
        {"key": "ai_service_actuel", "label": "Service actuel", "type": "text", "required": False},
        {"key": "ai_service_souhaite", "label": "Service souhaite", "type": "text", "required": False},
        {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": False},
    ],
    "material": [
        {"key": "ai_materiel_concerne", "label": "Materiel concerne", "type": "text", "required": True},
        {"key": "ai_quantite", "label": "Quantite", "type": "number", "required": False},
        {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": False},
    ],
    "leave": [
        {"key": "ai_type_conge", "label": "Type de conge", "type": "select", "required": True, "options": ["Conge annuel", "Conge maladie", "Conge paternite", "Conge maternite", "Conge sans solde"]},
        {"key": "ai_date_debut_conge", "label": "Date de debut", "type": "date", "required": True},
        {"key": "ai_date_fin_conge", "label": "Date de fin", "type": "date", "required": False},
    ],
    "maintenance": [
        {"key": "ai_equipement_concerne", "label": "Equipement concerne", "type": "text", "required": True},
        {"key": "ai_description_probleme", "label": "Description du probleme", "type": "textarea", "required": True},
    ],
    "schedule": [
        {"key": "ai_horaire_actuel", "label": "Horaire actuel", "type": "text", "required": True},
        {"key": "ai_horaire_souhaite", "label": "Horaire souhaite", "type": "text", "required": True},
        {"key": "ai_date_souhaitee_metier", "label": "Date souhaitee", "type": "date", "required": False},
        {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": False},
    ],
}


GENERIC_FALLBACK_FIELDS = [
    {"key": "ai_objectif", "label": "Objectif principal", "type": "text", "required": True},
    {"key": "ai_motif", "label": "Motif principal", "type": "textarea", "required": False},
    {"key": "ai_contexte", "label": "Contexte complementaire", "type": "textarea", "required": False},
]


ALLOWED_CUSTOM_FIELD_KEYS = {
    "ai_lieu_depart_actuel",
    "ai_lieu_souhaite",
    "ai_type_transport",
    "ai_date_souhaitee_metier",
    "ai_nom_formation",
    "ai_type_formation",
    "ai_organisme",
    "ai_montant",
    "ai_justification_metier",
    "ai_type_depense",
    "ai_systeme_concerne",
    "ai_type_acces",
    "ai_salle_souhaitee",
    "ai_date_reservation",
    "ai_duree",
    "ai_zone_souhaitee",
    "ai_type_stationnement",
    "ai_type_contrat",
    "ai_poste_souhaite",
    "ai_service_actuel",
    "ai_service_souhaite",
    "ai_materiel_concerne",
    "ai_quantite",
    "ai_type_conge",
    "ai_date_debut_conge",
    "ai_date_fin_conge",
    "ai_equipement_concerne",
    "ai_description_probleme",
    "ai_horaire_actuel",
    "ai_horaire_souhaite",
    "ai_objectif",
    "ai_motif",
    "ai_contexte",
}


def _is_allowed_custom_field_key(field_key: str) -> bool:
    key = normalize_ws(field_key)
    return bool(key) and (key in ALLOWED_CUSTOM_FIELD_KEYS or key.startswith("ai_custom_"))


def _has_leave_request_evidence(text: str) -> bool:
    return _has_any_phrase(norm(text), [norm(word) for word in LEAVE_REQUEST_TERMS])


def _detect_expense_type_from_text(text: str) -> str:
    lowered = norm(text)
    for label, keywords in EXPENSE_TYPE_EVIDENCE.items():
        if _has_any_phrase(lowered, [norm(keyword) for keyword in keywords]):
            return label
    return ""


CONSTRAINT_MARKERS = [
    "contrainte",
    "condition",
    "exige",
    "obligatoire",
    "uniquement",
    "seulement",
    "sans",
    "avec obligation",
    "a condition",
    "je prefere",
    "interdit",
    "pas de",
]


def _is_constraint_field_key(field_key: str) -> bool:
    normalized = norm(field_key).replace("_", " ")
    return bool(re.search(r"\b(contrainte|condition|preference|preference)\b", normalized))


def _has_constraint_evidence(text: str) -> bool:
    lowered = norm(text)
    if not lowered:
        return False
    return _has_any_phrase(lowered, [norm(marker) for marker in CONSTRAINT_MARKERS])


def _value_has_prompt_support(text: str, value: str) -> bool:
    lowered = norm(text)
    normalized_value = norm(value)
    if not lowered or not normalized_value:
        return False
    if _has_phrase(lowered, normalized_value):
        return True
    prompt_tokens = set(_tokenize(text))
    value_tokens = set(_tokenize(value))
    if not prompt_tokens or not value_tokens:
        return False
    overlap_ratio = len(prompt_tokens & value_tokens) / float(len(value_tokens))
    return overlap_ratio >= 0.6


def _field_has_minimal_evidence(field_key: str, text: str, value: str = "", source_kind: str = "inferred") -> bool:
    key = normalize_ws(field_key)
    lowered = norm(text)
    normalized_value = norm(value)

    if source_kind == "explicit":
        if _is_constraint_field_key(key):
            return _has_constraint_evidence(text)
        if key == "ai_type_conge":
            return _has_leave_request_evidence(text)
        if key in {"ai_date_debut_conge", "ai_date_fin_conge"}:
            return _has_leave_request_evidence(text)
        if key == "ai_type_depense":
            return bool(_detect_expense_type_from_text(text))
        return True

    if key == "ai_type_conge":
        return _has_leave_request_evidence(text)

    if _is_constraint_field_key(key):
        return _has_constraint_evidence(text)

    if key in {"ai_date_debut_conge", "ai_date_fin_conge"}:
        return _has_leave_request_evidence(text)

    if key == "ai_type_depense":
        detected = _detect_expense_type_from_text(text)
        if not detected:
            return False
        return not normalized_value or norm(detected) == normalized_value

    if key == "ai_type_stationnement":
        return _has_any_phrase(lowered, ["parking", "stationnement", "place reservee", "place reserve", "acces parking", "badge parking", "autorisation temporaire"])

    if key == "ai_type_formation":
        return _has_any_phrase(lowered, ["formation", "certification"])

    if key == "ai_nom_formation":
        return _has_any_phrase(lowered, ["formation", "certification"])

    if key == "ai_zone_souhaitee":
        if not _has_any_phrase(lowered, ["parking", "stationnement"]):
            return False
        if not _has_explicit_zone_evidence(text):
            return False
        if normalized_value and not _has_phrase(lowered, normalized_value):
            direct_zone = extract_descriptive_location(text, "parking")
            return bool(direct_zone and norm(direct_zone) == normalized_value)
        return True

    if key.startswith("ai_custom_"):
        if not normalized_value:
            return False
        return _value_has_prompt_support(text, value)

    if key in {"ai_horaire_actuel", "ai_horaire_souhaite"}:
        current, target = _extract_schedule_change(text)
        if not current or not target:
            return False
        if key == "ai_horaire_actuel":
            return not normalized_value or normalized_value == norm(current)
        return not normalized_value or normalized_value == norm(target)

    return True


FIELD_INTENT_AFFINITY = {
    "ai_lieu_depart_actuel": {"transport", "mission"},
    "ai_lieu_souhaite": {"transport", "mission"},
    "ai_type_transport": {"transport", "mission"},
    "ai_date_souhaitee_metier": {"transport", "formation", "finance", "access", "room", "parking", "contract", "career", "material", "leave", "maintenance", "mission", "refund", "workplace"},
    "ai_nom_formation": {"formation"},
    "ai_type_formation": {"formation"},
    "ai_organisme": {"formation"},
    "ai_montant": {"finance", "refund"},
    "ai_justification_metier": {"finance", "refund", "access", "contract", "career", "material", "leave", "maintenance", "mission"},
    "ai_type_depense": {"finance", "refund"},
    "ai_systeme_concerne": {"access"},
    "ai_type_acces": {"access"},
    "ai_salle_souhaitee": {"room"},
    "ai_date_reservation": {"room"},
    "ai_duree": {"room", "mission"},
    "ai_zone_souhaitee": {"parking"},
    "ai_type_stationnement": {"parking"},
    "ai_type_contrat": {"contract"},
    "ai_poste_souhaite": {"career"},
    "ai_service_actuel": {"career"},
    "ai_service_souhaite": {"career"},
    "ai_materiel_concerne": {"material"},
    "ai_quantite": {"material"},
    "ai_type_conge": {"leave"},
    "ai_date_debut_conge": {"leave"},
    "ai_date_fin_conge": {"leave"},
    "ai_equipement_concerne": {"maintenance"},
    "ai_description_probleme": {"maintenance"},
    "ai_horaire_actuel": {"schedule"},
    "ai_horaire_souhaite": {"schedule"},
    "ai_objectif": set(),
    "ai_motif": set(),
    "ai_contexte": set(),
}


INTENT_KEYWORDS = {
    "transport": ["transport", "deplacement", "trajet", "navette", "mission", "voyage", "aller", "retour"],
    "formation": ["formation", "certification", "cours", "atelier", "training", "workshop", "apprentissage"],
    "finance": ["remboursement", "avance", "salaire", "prime", "frais", "facture", "budget", "indemnite"],
    "access": ["acces", "badge", "vpn", "systeme", "logiciel", "application", "compte", "permission"],
    "room": ["salle", "reunion", "meeting", "conference", "reservation", "réservation"],
    "contract": ["contrat", "prolongation", "renouvellement", "cdi", "cdd"],
    "career": ["poste", "mutation", "promotion", "mobilite", "changement de poste"],
    "parking": ["parking", "stationnement", "place reservee", "place reserve", "badge parking", "acces parking"],
    "material": ["materiel", "matériel", "equipement", "équipement", "chaise", "ecran", "clavier", "souris"],
    "leave": ["conge", "congé", "absence", "repos", "maladie"],
    "maintenance": ["maintenance", "reparation", "réparation", "panne", "incident", "climatisation"],
    "mission": ["mission", "etranger", "étranger", "voyage"],
    "refund": ["remboursement", "restaurant", "taxi", "hotel", "hébergement", "frais"],
    "workplace": ["ergonomique", "bureau", "open space", "casier", "eclairage", "éclairage"],
    "schedule": ["changement d horaire", "horaire", "passer de", "8h", "9h", "17h", "18h"],
}


class DynamicFieldGenerator:
    def analyze_intent(self, text: str) -> list[tuple[str, float]]:
        source = normalize_ws(text)
        if not source:
            return []

        lowered = norm(source)
        scores: dict[str, float] = {intent: 0.0 for intent in INTENT_KEYWORDS}
        for intent, keywords in INTENT_KEYWORDS.items():
            for keyword in keywords:
                keyword_norm = norm(keyword)
                if not keyword_norm:
                    continue
                if _has_phrase(lowered, keyword_norm):
                    scores[intent] += 1.0 + max(0.0, min(0.75, len(keyword_norm.split()) * 0.15))
        if _is_time_sensitive(source):
            scores.setdefault("time_sensitive", 0.0)
            scores["time_sensitive"] += 1.0
        ranked = sorted(scores.items(), key=lambda item: item[1], reverse=True)
        total = sum(score for _, score in ranked) or 1.0
        intents = [(intent, round(score / total, 3)) for intent, score in ranked if score > 0]
        if not intents:
            intents = [("unknown", 1.0)]
        return intents

    def generate_field_plan(self, text: str, intents: list[tuple[str, float]] | None, miner: PatternMiner | None) -> list[dict[str, Any]]:
        source = normalize_ws(text)
        normalized_source = norm(source)
        intents = intents or self.analyze_intent(source)
        extractor = BoundaryAwareExtractor()
        has_constraint_evidence = _has_constraint_evidence(source)
        intent_names = [intent for intent, confidence in intents if confidence > 0.05]
        if not intent_names:
            intent_names = ["unknown"]

        learned_scores: list[tuple[str, str, float]] = miner.rank_candidate_fields(source, 8) if miner else []
        candidate_map: dict[str, DynamicFieldCandidate] = {}
        specific_field_added = False

        def get_source_rank(source_name: str) -> int:
            return {"explicit": 3, "inferred": 2, "default": 1}.get(source_name, 0)

        def infer_candidate_source(value: str, fallback: str = "inferred") -> str:
            normalized_value = norm(value)
            if normalized_value and _has_phrase(normalized_source, normalized_value):
                return "explicit"
            return fallback

        def add_candidate(definition: dict[str, Any], value: str = "", confidence: float = 0.0, source_kind: str = "inferred") -> None:
            key = normalize_ws(definition.get("key") or "")
            if not key:
                return
            source_kind = source_kind if source_kind in {"explicit", "inferred", "default"} else "inferred"
            if _is_constraint_field_key(key) and not has_constraint_evidence:
                return
            if _is_constraint_field_key(key) and source_kind != "explicit" and normalize_ws(value) and not _has_phrase(normalized_source, norm(value)):
                return
            if key == "ai_zone_souhaitee" and source_kind != "explicit" and normalize_ws(value) and not _has_phrase(normalized_source, norm(value)):
                return
            if key.startswith("ai_custom_") and source_kind != "explicit" and normalize_ws(value) and not _value_has_prompt_support(source, value):
                return
            if normalize_ws(definition.get("type") or "") == "select" and key.startswith("ai_custom_") and source_kind != "explicit":
                value = ""
            if source_kind != "explicit" and not _field_has_minimal_evidence(key, source, value, source_kind):
                return
            current = candidate_map.get(key)
            if current:
                current_rank = get_source_rank(current.source)
                new_rank = get_source_rank(source_kind)
                if current.value and current_rank > new_rank:
                    return
                if current.value and current_rank == new_rank and confidence <= current.confidence:
                    return
            candidate_map[key] = DynamicFieldCandidate(
                key=key,
                label=normalize_ws(definition.get("label") or key),
                type=normalize_ws(definition.get("type") or "text"),
                required=bool(definition.get("required", False)),
                value=normalize_ws(value),
                options=list(definition.get("options") or []),
                confidence=confidence,
                source=source_kind,
            )

        add_candidate({"key": "descriptionBesoin", "label": "Description du besoin", "type": "textarea", "required": True}, source, 1.0, "explicit")
        priority_label, priority_confidence = (miner.suggest_priority(source) if miner else (extractor._suggest_priority(source), 0.35))
        add_candidate(
            {"key": "niveauUrgenceAutre", "label": "Niveau d urgence", "type": "select", "required": True, "options": ["Faible", "Normale", "Urgente", "Tres urgente"]},
            priority_label,
            priority_confidence,
            infer_candidate_source(priority_label, "default"),
        )

        if _is_time_sensitive(source):
            date_value = extractor.extract_date(source)
            add_candidate(
                {"key": "dateSouhaiteeAutre", "label": "Date souhaitee", "type": "date", "required": False},
                date_value,
                0.75 if date_value else 0.0,
                infer_candidate_source(date_value, "default"),
            )

        for intent_name in intent_names:
            for blueprint in FIELD_BLUEPRINTS.get(intent_name, []):
                value = self._extract_blueprint_value(source, blueprint, extractor, miner)
                if not value:
                    continue
                confidence = self._estimate_blueprint_confidence(blueprint, value, source, miner)
                specific_field_added = True
                add_candidate(blueprint, value, confidence, infer_candidate_source(value, "inferred"))

        has_known_blueprint_intent = any(intent in FIELD_BLUEPRINTS for intent in intent_names)

        if not has_known_blueprint_intent:
            for field_key, value, confidence in learned_scores:
                if not field_key.startswith("ai_custom_"):
                    continue
                if self._field_semantic_bucket(field_key) == "long_text":
                    continue
                if confidence < 0.25:
                    continue
                label = self._label_from_field_key(field_key)
                add_candidate({"key": field_key, "label": label, "type": self._guess_type(field_key), "required": False}, value, confidence, "inferred")
                if value:
                    specific_field_added = True

        for field_key, value, confidence in learned_scores:
            if not _is_allowed_custom_field_key(field_key):
                continue
            if not has_known_blueprint_intent and not field_key.startswith("ai_custom_"):
                continue
            if self._field_semantic_bucket(field_key) == "long_text":
                continue
            if not self._field_matches_intents(field_key, intent_names):
                continue
            if "date" in field_key and not _is_time_sensitive(source):
                continue
            if not value:
                continue
            if field_key in candidate_map:
                existing = candidate_map[field_key]
                if _is_constraint_field_key(field_key) and not has_constraint_evidence:
                    continue
                if _is_constraint_field_key(field_key) and not _has_phrase(normalized_source, norm(value)):
                    continue
                if existing.source == "explicit":
                    continue
                if confidence > existing.confidence and value:
                    existing.value = normalize_ws(value)
                    existing.confidence = confidence
                    if get_source_rank(existing.source) < get_source_rank("inferred"):
                        existing.source = "inferred"
                continue
            if confidence < 0.35:
                continue
            add_candidate({"key": field_key, "label": self._label_from_field_key(field_key), "type": self._guess_type(field_key), "required": False}, value, confidence, "inferred")
            if value:
                specific_field_added = True

        cooccurring_keys = self._expand_from_cooccurrence(candidate_map.keys(), miner) if has_known_blueprint_intent else []
        for key in cooccurring_keys:
            if not _is_allowed_custom_field_key(key):
                continue
            if self._field_semantic_bucket(key) == "long_text":
                continue
            if not self._field_matches_intents(key, intent_names):
                continue
            if "date" in key and not _is_time_sensitive(source):
                continue
            if key in candidate_map:
                continue
            learned_value, learned_confidence = miner.predict_field(key, source) if miner else ("", 0.0)
            if learned_value and learned_confidence >= 0.3:
                add_candidate({"key": key, "label": self._label_from_field_key(key), "type": self._guess_type(key), "required": False}, learned_value, learned_confidence, "inferred")
                specific_field_added = True

        if not specific_field_added and not has_known_blueprint_intent:
            for definition in GENERIC_FALLBACK_FIELDS:
                value = self._extract_generic_fallback_value(source, definition["key"], extractor, miner)
                confidence = 0.45 if value else 0.15
                add_candidate(definition, value, confidence, "default")

        dynamic_custom_fields = self._extract_dynamic_custom_fields(source, extractor)
        for dynamic_field in dynamic_custom_fields:
            add_candidate(
                {
                    "key": dynamic_field["key"],
                    "label": dynamic_field["label"],
                    "type": dynamic_field["type"],
                    "required": dynamic_field.get("required", False),
                },
                dynamic_field["value"],
                float(dynamic_field.get("confidence", 0.6)),
                str(dynamic_field.get("source", "explicit")),
            )
            if normalize_ws(dynamic_field.get("value", "")):
                specific_field_added = True

        ordered = sorted(
            candidate_map.values(),
            key=lambda item: (
                item.source != "explicit",
                not item.required,
                -item.confidence,
                item.key,
            ),
        )
        explicit_fields = [item for item in ordered if item.source == "explicit"]
        non_explicit_fields = [item for item in ordered if item.source != "explicit"]
        max_fields = 8
        remaining_slots = max(0, max_fields - len(explicit_fields))
        trimmed = explicit_fields + non_explicit_fields[:remaining_slots]

        return [candidate.to_dict() for candidate in trimmed]

    def _extract_blueprint_value(self, text: str, blueprint: dict[str, Any], extractor: BoundaryAwareExtractor, miner: PatternMiner | None) -> str:
        key = normalize_ws(blueprint.get("key") or "")
        lowered = norm(text)
        schedule_current, schedule_target = _extract_schedule_change(text)

        if key == "ai_lieu_depart_actuel" or key == "ai_lieu_souhaite":
            depart, destination = extractor.extract_location_pair(text)
            return depart if key == "ai_lieu_depart_actuel" else destination
        if key == "ai_nom_formation":
            return extractor.extract_subject_name(text, "formation") or extractor.extract_subject_name(text, "certification")
        if key == "ai_type_formation":
            if re.search(r"\bcertification\b", lowered):
                return "Certification"
            return "Formation externe" if extractor.extract_subject_name(text, "formation") else ""
        if key == "ai_date_souhaitee_metier":
            return extractor.extract_date(text)
        if key == "ai_montant":
            return extractor.extract_amount(text)
        if key == "ai_justification_metier":
            return self._extract_justification(text)
        if key == "ai_systeme_concerne":
            return extractor.extract_subject_name(text, "acces") or self._extract_after_any(text, ["vpn", "badge", "systeme", "logiciel", "application"])
        if key == "ai_type_acces":
            return self._infer_access_type(text)
        if key == "ai_salle_souhaitee":
            return self._extract_after_any(text, ["salle", "reunion", "meeting", "conference"])
        if key == "ai_date_reservation":
            return extractor.extract_date(text)
        if key == "ai_duree":
            return extractor._extract_duration(text)
        if key == "ai_zone_souhaitee":
            return extract_descriptive_location(text, "parking") or ""
        if key == "ai_horaire_actuel":
            return schedule_current
        if key == "ai_horaire_souhaite":
            return schedule_target
        if key == "ai_type_stationnement":
            if re.search(r"\b(moto|deux roues)\b", lowered):
                return "Autorisation temporaire"
            return "Place reservee"
        if key == "ai_type_contrat":
            return self._extract_after_any(text, ["contrat", "cdi", "cdd"])
        if key == "ai_poste_souhaite":
            return self._extract_after_any(text, ["poste", "promotion", "mutation"])
        if key == "ai_service_actuel" or key == "ai_service_souhaite":
            return self._extract_service(text, key)
        if key == "ai_materiel_concerne":
            return self._extract_after_any(text, ["materiel", "matériel", "equipement", "équipement", "chaise", "ecran", "écran", "clavier", "souris"])
        if key == "ai_quantite":
            match = re.search(r"\b(\d+)\b", text)
            return match.group(1) if match else ""
        if key == "ai_type_conge":
            return extractor._infer_leave_type(text) if _has_leave_request_evidence(text) else ""
        if key == "ai_date_debut_conge":
            return extractor.extract_date(text) if _has_leave_request_evidence(text) else ""
        if key == "ai_date_fin_conge":
            if not _has_leave_request_evidence(text):
                return ""
            _, end = extractor.extract_date_range(text)
            return end
        if key == "ai_type_depense":
            return _detect_expense_type_from_text(text)
        if key == "ai_equipement_concerne":
            return self._extract_after_any(text, ["maintenance", "reparation", "réparation", "climatisation", "panne", "incident"])
        if key == "ai_description_probleme":
            return text
        if key == "ai_type_transport":
            return extractor._infer_transport_type(text)

        return ""

    def _estimate_blueprint_confidence(self, blueprint: dict[str, Any], value: str, text: str, miner: PatternMiner | None) -> float:
        if not value:
            return 0.0
        confidence = 0.4
        if blueprint.get("required"):
            confidence += 0.15
        if len(value.split()) == 1:
            confidence += 0.1
        if _has_phrase(norm(text), norm(value)):
            confidence += 0.2
        if miner:
            learned_value, learned_confidence = miner.predict_field(str(blueprint.get("key") or ""), text)
            if learned_value and norm(learned_value) == norm(value):
                confidence = max(confidence, learned_confidence)
        return min(0.99, confidence)

    def _expand_from_cooccurrence(self, keys: Iterable[str], miner: PatternMiner | None) -> list[str]:
        if not miner:
            return []
        related: list[str] = []
        for key in keys:
            for linked in miner.field_cooccurrence.get(str(key), []):
                if linked not in related:
                    related.append(linked)
        return related[:6]

    def _label_from_field_key(self, field_key: str) -> str:
        return normalize_ws(field_key).replace("ai_", "").replace("_", " ").title() or "Champ complementaire"

    def _guess_type(self, field_key: str) -> str:
        key = norm(field_key)
        if "date" in key:
            return "date"
        if "montant" in key or "quantite" in key or "quantité" in key:
            return "number"
        if "description" in key or "justification" in key or "contexte" in key:
            return "textarea"
        if "type_" in key or key.endswith("_type") or "urgence" in key:
            return "select"
        return "text"

    def _extract_after_any(self, text: str, keywords: Iterable[str]) -> str:
        source = normalize_ws(text)
        stop_pattern = "|".join(map(re.escape, STOP_MARKERS))
        for keyword in keywords:
            pattern = rf"\b{re.escape(keyword)}\b(?:\s+(?:de|du|des|sur|en|pour|a|à|d['’]))?\s+(.+?)(?=\s+(?:{stop_pattern})\b|\b\d{{4}}\b|\b\d{{1,2}}[/-]\d{{1,2}}\b|$)"
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if match:
                candidate = clean_entity_text(match.group(1))
                if candidate:
                    return capitalize_entity(candidate)
        return ""

    def _extract_justification(self, text: str) -> str:
        source = normalize_ws(text)
        lowered = norm(source)
        match = re.search(r"\b(?:pour|car|afin|dans le cadre de)\s+(.+?)(?=\s+(?:des|le|du|de|vers|pour|afin|car|avec|a partir|depuis|jusqu|et|ou|qui|que)\b|\b\d{4}\b|\b\d{1,2}[/-]\d{1,2}\b|$)", source, flags=re.IGNORECASE)
        if match:
            candidate = clean_entity_text(match.group(1))
            if candidate:
                return candidate[:300]
        if lowered:
            return source[:300]
        return ""

    def _infer_access_type(self, text: str) -> str:
        lowered = norm(text)
        if _has_any_phrase(lowered, ["administrateur", "admin"]):
            return "Administrateur"
        if _has_any_phrase(lowered, ["lecture ecriture", "ecriture"]):
            return "Lecture/Ecriture"
        if _has_any_phrase(lowered, ["lecture seule", "consultation", "voir"]):
            return "Lecture seule"
        return "Autre"

    def _extract_service(self, text: str, key: str) -> str:
        source = normalize_ws(text)
        if key == "ai_service_actuel":
            return self._extract_after_any(source, ["service", "departement", "département", "equipe", "équipe"])
        return self._extract_after_any(source, ["vers", "pour", "service", "departement", "département", "equipe", "équipe"])

    def _contains_any(self, text: str, keywords: Iterable[str]) -> bool:
        haystack = norm(text)
        return _has_any_phrase(haystack, [norm(keyword) for keyword in keywords])

    def _extract_generic_fallback_value(self, text: str, field_key: str, extractor: BoundaryAwareExtractor, miner: PatternMiner | None) -> str:
        source = normalize_ws(text)
        lowered = norm(source)

        if field_key == "ai_objectif":
            if self._contains_any(lowered, ["transport", "deplacement", "trajet"]):
                return "Organiser un transport"
            if self._contains_any(lowered, ["formation", "certification", "cours"]):
                return "Suivre une formation"
            if self._contains_any(lowered, ["acces", "badge", "vpn"]):
                return "Obtenir un acces"
            if self._contains_any(lowered, ["salle", "reunion", "meeting"]):
                return "Reserver une salle"
            if self._contains_any(lowered, ["remboursement", "avance", "frais", "prime"]):
                return "Demande financiere"
            return self._first_meaningful_span(source)

        if field_key == "ai_motif":
            return self._extract_justification(source)

        if field_key == "ai_contexte":
            return source[:240]

        return ""

    def _field_matches_intents(self, field_key: str, intent_names: Iterable[str]) -> bool:
        affinity = FIELD_INTENT_AFFINITY.get(field_key)
        if affinity is None:
            return True
        if not affinity:
            return False
        intents = set(intent_names)
        if not intents:
            return False
        if "time_sensitive" in intents and field_key == "ai_date_souhaitee_metier":
            return True
        return bool(affinity & intents)

    def _field_semantic_bucket(self, field_key: str) -> str:
        key = norm(field_key)
        if "date" in key:
            return "date"
        if "montant" in key or "quantite" in key or "quantité" in key:
            return "number"
        if "description" in key or "justification" in key or "contexte" in key or "motif" in key:
            return "long_text"
        if "type_" in key or key.endswith("_type") or "urgence" in key:
            return "select"
        return "text"

    def _extract_dynamic_custom_fields(self, text: str, extractor: BoundaryAwareExtractor) -> list[dict[str, Any]]:
        source = normalize_ws(text)
        if not source:
            return []

        fields: list[dict[str, Any]] = []
        seen_keys: set[str] = set()

        for label, value in self._extract_labeled_pairs(source):
            field_key = self._make_dynamic_field_key(label)
            if not field_key or field_key in seen_keys:
                continue
            field_type = self._guess_dynamic_field_type(value)
            normalized_value = self._normalize_dynamic_field_value(value, field_type, extractor)
            if not normalized_value:
                continue
            fields.append({
                "key": field_key,
                "label": normalize_ws(label)[:48],
                "type": field_type,
                "required": False,
                "value": normalized_value,
                "confidence": 0.82,
                "source": "explicit",
            })
            seen_keys.add(field_key)
            if len(fields) >= 4:
                return fields

        clause_candidates = [
            ("Objet", self._extract_after_connectors(source, ["concernant", "au sujet de", "a propos de", "pour"])),
            ("Contrainte", self._extract_constraint_clause(source)),
            ("Beneficiaire", self._extract_explicit_beneficiary(source)),
        ]
        for label, value in clause_candidates:
            if not value:
                continue
            field_key = self._make_dynamic_field_key(label)
            if field_key in seen_keys:
                continue
            fields.append({
                "key": field_key,
                "label": label,
                "type": "text" if len(value) <= 80 else "textarea",
                "required": False,
                "value": value,
                "confidence": 0.58,
                "source": "inferred",
            })
            seen_keys.add(field_key)
            if len(fields) >= 4:
                break

        return fields[:4]

    def _extract_labeled_pairs(self, text: str) -> list[tuple[str, str]]:
        pairs: list[tuple[str, str]] = []
        pattern = re.compile(
            r"(?:^|[\n;,]\s*)([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ0-9 '/_-]{2,32})\s*[:=-]\s*([^;\n,]{2,120})",
            flags=re.IGNORECASE,
        )
        for match in pattern.finditer(text):
            label = normalize_ws(match.group(1))
            value = normalize_ws(match.group(2)).strip(" .")
            if not label or not value:
                continue
            pairs.append((label, value))
        return pairs

    def _make_dynamic_field_key(self, label: str) -> str:
        slug = norm(label).replace(" ", "_")
        slug = re.sub(r"[^a-z0-9_]+", "_", slug)
        slug = re.sub(r"_+", "_", slug).strip("_")
        if not slug:
            return ""
        return f"ai_custom_{slug[:28]}"

    def _guess_dynamic_field_type(self, value: str) -> str:
        cleaned = normalize_ws(value)
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", cleaned):
            return "date"
        if re.fullmatch(r"\d+(?:[.,]\d+)?", cleaned):
            return "number"
        if len(cleaned) > 80:
            return "textarea"
        return "text"

    def _normalize_dynamic_field_value(self, value: str, field_type: str, extractor: BoundaryAwareExtractor) -> str:
        cleaned = normalize_ws(value)
        if not cleaned:
            return ""
        if field_type == "date":
            return extractor.extract_date(cleaned) or cleaned
        if field_type == "number":
            return cleaned.replace(",", ".")
        if field_type == "textarea":
            return cleaned[:220]
        return clean_entity_text(cleaned) or cleaned[:120]

    def _extract_after_connectors(self, text: str, connectors: Iterable[str]) -> str:
        source = normalize_ws(text)
        for connector in connectors:
            pattern = rf"\b{re.escape(connector)}\b\s+(.+?)(?=\s+(?:avec|sans|pour|afin|car|le|la|les|du|de|des)\b|[\.,;]|$)"
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if not match:
                continue
            candidate = clean_entity_text(match.group(1))
            if candidate and len(candidate) >= 3:
                return candidate[:120]
        return ""

    def _extract_explicit_beneficiary(self, text: str) -> str:
        source = normalize_ws(text)
        if not source:
            return ""

        patterns = [
            r"\bbeneficiaire\s*[:\-]\s*([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){1,3})\b",
            r"\bau\s+nom\s+de\s+([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){1,3})\b",
            r"\bpour\s+([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){1,3})\b",
        ]

        for pattern in patterns:
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if not match:
                continue

            candidate = clean_entity_text(match.group(1))
            if not candidate:
                continue

            words = [part for part in re.split(r"\s+", candidate) if part]
            if len(words) < 2:
                continue
            if len(words) > 4:
                words = words[:4]

            valid = True
            for word in words:
                token = norm(word)
                if not token or token in BENEFICIARY_BLOCKLIST or not is_likely_proper_noun(token):
                    valid = False
                    break

            if valid:
                return capitalize_entity(" ".join(words))

        return ""

    def _extract_constraint_clause(self, text: str) -> str:
        source = normalize_ws(text)
        if not source or not _has_constraint_evidence(source):
            return ""

        patterns = [
            (r"\b(sans\s+[a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2})\b", None),
            (r"\b(pas\s+de\s+[a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2})\b", None),
            (r"\b(?:uniquement|seulement)\s+(?:en\s+)?([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2})\b", "Uniquement"),
            (r"\b(?:je\s+prefere|je\s+préfère)\s+([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,3})\b", "Je prefere"),
            (r"\b(?:a\s+condition\s+de|à\s+condition\s+de|avec\s+obligation\s+de)\s+([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,4})\b", "A condition de"),
        ]

        for pattern, prefix in patterns:
            match = re.search(pattern, source, flags=re.IGNORECASE)
            if not match:
                continue
            captured = clean_entity_text(match.group(1))
            if not captured:
                continue
            if prefix:
                return capitalize_entity(f"{prefix} {captured}")
            return capitalize_entity(captured)

        return "Contrainte explicite"

    def _first_meaningful_span(self, text: str) -> str:
        tokens = [token for token in re.split(r"\s+", normalize_ws(text)) if token]
        content = [token for token in tokens if norm(token) not in {"je", "veux", "voudrais", "souhaite", "demande", "une", "un", "de", "du", "des", "la", "le", "les", "pour", "afin", "car", "avec", "et", "ou", "qui", "que"}]
        return capitalize_entity(" ".join(content[:5])) if content else normalize_ws(text)[:80]
