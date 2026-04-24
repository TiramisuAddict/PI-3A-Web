import json
import math
import random
import re
import sys
from calendar import monthrange
import unicodedata
from difflib import get_close_matches
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path

import demande_dynamic_extractors as dyn_extract
from text_match_utils import _has_any_phrase, _has_any_word, _has_phrase, _has_word


# ═══════════════════════════════════════════════════════════════
# TEXT UTILITIES
# ═══════════════════════════════════════════════════════════════

def _normalize_ws(text):
    return re.sub(r"\s+", " ", str(text or "")).strip()


def _norm(text):
    value = unicodedata.normalize("NFD", str(text or ""))
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    return _normalize_ws(value).lower()


def _sentence_case(text):
    cleaned = _normalize_ws(text)
    if not cleaned:
        return ""
    parts = re.split(r"([.!?]\s+)", cleaned)
    chunks = []
    for part in parts:
        if not part:
            continue
        if re.fullmatch(r"[.!?]\s+", part):
            chunks.append(part)
            continue
        chunks.append(part[:1].upper() + part[1:])
    return "".join(chunks).strip()


def _strip_autre_prompt_boilerplate(text):
    cleaned = _normalize_ws(text)
    if not cleaned:
        return ""

    patterns = [
        r"^(?:bonjour|salut|bonsoir)\s*[,;:\-]?\s*",
        r"\bmon besoin concerne precisement\b",
        r"\bdans le cadre de mon activite professionnelle\b",
        r"\bje reste disponible pour tout complement d information\b\.?",
        r"\bmerci de passer cette information\b\.?",
        r"\btype\s*:\s*[^.]+\.?",
        r"\bcategorie\s*:\s*[^.]+\.?",
        r"\b(?:je souhaite|je voudrais|je veux)\s+(?:demander|soumettre|mettre|faire|creer|créer)\s+",
        r"\bune?\s+demande\s+(?:liee|liée)\s+a\s+",
    ]
    for pattern in patterns:
        cleaned = re.sub(pattern, " ", cleaned, flags=re.IGNORECASE)

    cleaned = re.sub(r"\s+", " ", cleaned)
    cleaned = re.sub(r"\s+([,.;:])", r"\1", cleaned)
    cleaned = cleaned.strip(" ,;:-.")

    duplicate_match = re.match(r"(.{12,160}?)\.\s+\1\b", cleaned, flags=re.IGNORECASE)
    if duplicate_match:
        cleaned = duplicate_match.group(1)

    return _normalize_ws(cleaned)


def _is_generic_autre_subject_value(value):
    normalized = _norm(value)
    if not normalized:
        return True

    generic_values = {
        "professionnel",
        "professionnelle",
        "formation",
        "formation professionnelle",
        "formation professionel",
        "certification",
        "autre",
        "demande",
        "objet",
        "besoin",
    }
    return normalized in generic_values


# ═══════════════════════════════════════════════════════════════
# AUTRE PATTERN MINER SUPPORT
# ═══════════════════════════════════════════════════════════════

_AUTRE_MINER_CACHE = {"path": "", "mtime": None, "miner": None}


def _workspace_root():
    return Path(__file__).resolve().parents[1]


def _autre_data_dir():
    return _workspace_root() / "var" / "ai"


def _autre_db_training_path():
    return _autre_data_dir() / "db_demandes_with_details.json"


def _autre_feedback_path():
    return _autre_data_dir() / "autre_generation_feedback.jsonl"


def _autre_pattern_miner_path():
    return _autre_data_dir() / "autre_pattern_miner.json"


def _autre_source_paths():
    return [
        _autre_db_training_path(),
        _autre_feedback_path(),
    ]


def _read_json_file(path):
    source = Path(path)
    if not source.exists():
        return None
    try:
        return json.loads(source.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def _read_jsonl_file(path):
    source = Path(path)
    if not source.exists():
        return []
    rows = []
    try:
        with source.open("r", encoding="utf-8") as handle:
            for line in handle:
                line = line.strip()
                if not line:
                    continue
                try:
                    rows.append(json.loads(line))
                except json.JSONDecodeError:
                    continue
    except OSError:
        return []
    return rows


def _normalize_autre_detail_items(details):
    if isinstance(details, dict):
        return [
            {
                "fieldKey": str(key),
                "fieldValue": _sanitize_autre_training_value(str(key), value),
                "fieldType": "text",
            }
            for key, value in details.items()
        ]
    if isinstance(details, list):
        normalized_items = []
        for item in details:
            if not isinstance(item, dict):
                continue
            field_key = str(item.get("fieldKey") or item.get("key") or "")
            normalized_items.append({
                **item,
                "fieldKey": field_key,
                "fieldValue": _sanitize_autre_training_value(field_key, item.get("fieldValue") if "fieldValue" in item else item.get("value")),
            })
        return normalized_items
    return []


def _sanitize_autre_training_value(field_key, value):
    key = _normalize_ws(field_key)
    if not key:
        return _normalize_ws(value)

    text = _normalize_ws(value)
    if not text:
        return ""

    if key in {"dateSouhaiteeAutre", "ai_date_souhaitee_metier", "ai_date_fin_conge", "ai_date_souhaitee_extra"}:
        return text if re.fullmatch(r"\d{4}-\d{2}-\d{2}", text) else ""

    if key == "ai_zone_souhaitee":
        text = re.sub(r"\b(?:le|la|les|du|de|des)\s+\d{1,2}(?:er)?(?:\s+[a-zà-ÿ]+)?\b.*$", "", text, flags=re.IGNORECASE)
        text = re.sub(r"\b(?:car|parce\s+que|afin\s+de|pour)\b.*$", "", text, flags=re.IGNORECASE)
        text = re.sub(r"\b(?:tot|t[oô]t)\s+le\s+matin\b.*$", "", text, flags=re.IGNORECASE)
        text = re.sub(r"\b(?:matin|apres\s*midi|soir|nuit)\b.*$", "", text, flags=re.IGNORECASE)
        text = _normalize_ws(text).strip(" ,;:-")
        if text:
            text = text[:1].upper() + text[1:]
        return text

    if key == "ai_horaire_arrivee":
        return text

    if key in {"ai_nom_formation", "ai_lieu_depart_actuel", "ai_lieu_souhaite", "ai_systeme_concerne", "ai_localisation_souhaitee"}:
        cleaned = dyn_extract.clean_entity_text(text)
        if key == "ai_nom_formation" and _is_generic_autre_subject_value(cleaned):
            return ""
        return cleaned[:1].upper() + cleaned[1:] if cleaned else ""

    if key.startswith("ai_") and ("justification" not in key and "description" not in key):
        cleaned = dyn_extract.clean_entity_text(text)
        return cleaned or text

    return text


def _normalize_autre_training_record(record):
    if not isinstance(record, dict):
        return None

    general = record.get("general") if isinstance(record.get("general"), dict) else {}
    prompt = _strip_autre_prompt_boilerplate(_normalize_ws(
        record.get("rawPrompt")
        or record.get("prompt")
        or record.get("text")
        or record.get("rawText")
        or record.get("userPromptAutre")
        or general.get("description")
        or record.get("description")
        or record.get("titre")
        or general.get("titre")
        or ""
    ))

    details = _normalize_autre_detail_items(record.get("details"))
    if not prompt and not details:
        return None

    return {
        "prompt": prompt,
        "text": prompt,
        "titre": _normalize_ws(record.get("titre") or record.get("title") or general.get("titre") or ""),
        "description": _normalize_ws(record.get("description") or general.get("description") or ""),
        "priorite": _normalize_ws(record.get("priorite") or record.get("priority") or general.get("priorite") or "NORMALE").upper() or "NORMALE",
        "categorie": _normalize_ws(record.get("categorie") or general.get("categorie") or "Autre") or "Autre",
        "typeDemande": _normalize_ws(record.get("typeDemande") or general.get("typeDemande") or "Autre") or "Autre",
        "general": {
            "titre": _normalize_ws(general.get("titre") or record.get("titre") or record.get("title") or ""),
            "description": _normalize_ws(general.get("description") or record.get("description") or ""),
            "priorite": _normalize_ws(general.get("priorite") or record.get("priorite") or "NORMALE").upper() or "NORMALE",
            "categorie": _normalize_ws(general.get("categorie") or record.get("categorie") or "Autre") or "Autre",
            "typeDemande": _normalize_ws(general.get("typeDemande") or record.get("typeDemande") or "Autre") or "Autre",
        },
        "details": details,
    }


def _load_autre_training_samples():
    samples = []

    db_rows = _read_json_file(_autre_db_training_path())
    if isinstance(db_rows, list):
        for row in db_rows:
            sample = _normalize_autre_training_record(row)
            if sample:
                samples.append(sample)

    feedback_rows = _read_jsonl_file(_autre_feedback_path())
    for row in feedback_rows:
        sample = _normalize_autre_training_record(row)
        if sample:
            samples.append(sample)

    return samples


def _build_autre_pattern_miner():
    miner = dyn_extract.PatternMiner()
    miner.fit(_load_autre_training_samples())
    return miner


def _miner_has_suspicious_autre_values(miner):
    value_counts = getattr(miner, "value_counts", {}) or {}
    formation_values = value_counts.get("ai_nom_formation", {}) if isinstance(value_counts, dict) else {}
    if not isinstance(formation_values, dict):
        return False

    for candidate in formation_values.keys():
        if _is_generic_autre_subject_value(candidate):
            return True

    return False


def _load_autre_pattern_miner(force_refresh=False):
    path = _autre_pattern_miner_path()
    cache_key = str(path)
    try:
        mtime = path.stat().st_mtime if path.exists() else None
    except OSError:
        mtime = None

    cached = _AUTRE_MINER_CACHE.get("miner")
    if not force_refresh and cached is not None and _AUTRE_MINER_CACHE.get("path") == cache_key and _AUTRE_MINER_CACHE.get("mtime") == mtime:
        return cached

    latest_source_mtime = None
    for source_path in _autre_source_paths():
        try:
            source_mtime = source_path.stat().st_mtime if source_path.exists() else None
        except OSError:
            source_mtime = None
        if source_mtime is not None and (latest_source_mtime is None or source_mtime > latest_source_mtime):
            latest_source_mtime = source_mtime

    miner = dyn_extract.PatternMiner.load(path) if path.exists() else dyn_extract.PatternMiner()
    miner_outdated = (
        latest_source_mtime is not None
        and (mtime is None or latest_source_mtime > mtime)
    )
    if miner_outdated or not getattr(miner, "total_samples", 0) or not getattr(miner, "value_counts", {}) or _miner_has_suspicious_autre_values(miner):
        miner = _build_autre_pattern_miner()
        if getattr(miner, "total_samples", 0) > 0:
            miner.save(path)
            try:
                mtime = path.stat().st_mtime
            except OSError:
                mtime = None

    _AUTRE_MINER_CACHE["path"] = cache_key
    _AUTRE_MINER_CACHE["mtime"] = mtime
    _AUTRE_MINER_CACHE["miner"] = miner
    return miner


def _autre_priority_to_base_label(label, confidence=0.0):
    label = _normalize_ws(label).upper()
    if label == "HAUTE":
        return "Tres urgente" if confidence >= 0.75 else "Urgente"
    if label == "BASSE":
        return "Faible"
    return "Normale"


def _autre_base_priority(label):
    label = _normalize_ws(label).upper()
    if label == "HAUTE":
        return "HAUTE"
    if label == "BASSE":
        return "BASSE"
    return "NORMALE"


def _autre_detect_explicit_urgency(source):
    normalized = _norm(source)
    if not normalized:
        return None

    critical_patterns = [
        r"\btres\s+urgent\b",
        r"\bextremement\s+urgent\b",
        r"\bimmediatement\b",
        r"\bdes\s+maintenant\b",
        r"\bbloquant\b",
        r"\bbloque\b",
        r"\bincident\s+critique\b",
        r"\bserveur\s+down\b",
        r"\baccident\b",
        r"\bhospitalisation\b",
    ]
    high_patterns = [
        r"\burgent\b",
        r"\burgence\b",
        r"\bau\s+plus\s+vite\b",
        r"\basap\b",
        r"\bdes\s+aujourd\s*hui\b",
    ]
    low_patterns = [
        r"\bpas\s+urgent\b",
        r"\bquand\s+possible\b",
        r"\bnon\s+urgent\b",
        r"\bbasse\s+priorite\b",
        r"\bconfort\b",
    ]

    if any(re.search(pattern, normalized, flags=re.IGNORECASE) for pattern in critical_patterns):
        return {
            "priority": "HAUTE",
            "urgency": "Tres urgente",
            "detected": True,
            "level": "critical",
        }

    if any(re.search(pattern, normalized, flags=re.IGNORECASE) for pattern in high_patterns):
        return {
            "priority": "HAUTE",
            "urgency": "Urgente",
            "detected": True,
            "level": "high",
        }

    if any(re.search(pattern, normalized, flags=re.IGNORECASE) for pattern in low_patterns):
        return {
            "priority": "BASSE",
            "urgency": "Faible",
            "detected": True,
            "level": "low",
        }

    return None


def _autre_clean_field_value(field_key, field_value, prompt, extractor):
    key = _normalize_ws(field_key)
    value = _normalize_ws(field_value)
    if not value:
        return ""

    lowered = _norm(prompt)
    if key in {"ai_lieu_depart_actuel", "ai_lieu_souhaite"}:
        depart, destination = extractor.extract_location_pair(prompt)
        return depart if key == "ai_lieu_depart_actuel" else destination
    if key == "ai_nom_formation":
        return extractor.extract_subject_name(prompt, "formation") or extractor.extract_subject_name(prompt, "certification")
    if key == "ai_type_formation":
        if _has_word(lowered, "certification"):
            return "Certification"
        return "Formation externe" if extractor.extract_subject_name(prompt, "formation") else value
    if key in {"ai_date_souhaitee_metier", "ai_date_reservation", "ai_date_debut_conge", "dateSouhaiteeAutre"}:
        return extractor.extract_date(prompt) or value
    if key == "ai_date_fin_conge":
        _, end = extractor.extract_date_range(prompt)
        return end or value
    if key in {"ai_montant", "ai_quantite"}:
        extracted = extractor.extract_amount(prompt)
        return extracted or value
    if key == "ai_systeme_concerne":
        return extractor.extract_subject_name(prompt, "acces") or value
    if key == "ai_type_acces":
        return value if value else "Autre"
    if key == "ai_type_depense":
        lowered = _norm(prompt)
        if _has_any_word(lowered, ["hotel", "hebergement", "nuitee", "nuit", "hébergement", "nuitée"]):
            return "Hotel"
        if _has_any_word(lowered, ["restaurant", "repas"]):
            return "Restaurant"
        if _has_any_word(lowered, ["taxi", "bus", "train", "avion", "vol"]):
            return "Transport"
        return ""
    if key == "ai_type_transport":
        extracted = extractor._infer_transport_type(prompt)
        if extracted:
            return extracted
        if not _source_supports_field_override(prompt, "type_transport_souhaite"):
            return "A definir"
        return value or "A definir"
    if key == "ai_zone_souhaitee":
        extracted_zone = dyn_extract.extract_descriptive_location(prompt, "parking") or _extract_parking_zone(prompt)
        if extracted_zone:
            return extracted_zone
        if _source_supports_field_override(prompt, "ai_zone_souhaitee"):
            return value
        return ""
    if key in {"ai_horaire_actuel", "ai_horaire_souhaite"}:
        current, target = dyn_extract._extract_schedule_change(prompt)
        if key == "ai_horaire_actuel":
            return current or value
        return target or value
    if key == "ai_type_stationnement":
        extracted_type = _infer_parking_type(prompt)
        return extracted_type or value or "Place reservee"
    if key == "ai_type_conge":
        return extractor._infer_leave_type(prompt)
    if key in {"ai_salle_souhaitee", "ai_localisation_souhaitee", "ai_poste_souhaite", "ai_materiel_concerne", "ai_type_contrat", "ai_equipement_concerne"}:
        cleaned = dyn_extract.clean_entity_text(value)
        return cleaned or value
    if key in {"ai_justification_metier", "ai_description_probleme", "descriptionBesoin"}:
        return _normalize_ws(value)
    if key.startswith("ai_custom_"):
        cleaned = dyn_extract.clean_entity_text(value)
        return cleaned or value[:180]
    return dyn_extract.clean_entity_text(value) or value


def _autre_title_head(intent_names, details, lowered):
    if details.get("ai_type_conge"):
        return details.get("ai_type_conge")
    if details.get("ai_type_contrat"):
        return details.get("ai_type_contrat")
    if details.get("ai_type_stationnement"):
        return "Parking"
    if details.get("ai_type_acces") or details.get("ai_systeme_concerne"):
        return "Acces"
    if details.get("ai_montant"):
        return "Demande financiere"
    if "transport" in intent_names:
        return "Transport"
    if "formation" in intent_names:
        return "Certification" if details.get("ai_type_formation") == "Certification" or _has_word(lowered, "certification") else "Formation"
    if "room" in intent_names:
        return "Reservation"
    if "maintenance" in intent_names:
        return "Maintenance"
    if "material" in intent_names:
        return "Materiel"
    if "career" in intent_names:
        return "Mobilite"
    if "contract" in intent_names:
        return "Contrat"
    return "Demande"


def _autre_title_fragments(details):
    fragments = []
    preferred_keys = [
        "ai_nom_formation",
        "ai_systeme_concerne",
        "ai_salle_souhaitee",
        "ai_zone_souhaitee",
        "ai_poste_souhaite",
        "ai_materiel_concerne",
        "ai_equipement_concerne",
        "ai_lieu_souhaite",
        "ai_lieu_depart_actuel",
        "ai_type_depense",
        "ai_organisme",
    ]

    for key in preferred_keys:
        value = _normalize_ws(details.get(key, ""))
        if value and value not in fragments:
            fragments.append(value)

    dynamic_pairs = []
    for key, raw_value in details.items():
        if not str(key).startswith("ai_custom_"):
            continue
        value = _normalize_ws(raw_value)
        if not value:
            continue
        label = str(key).replace("ai_custom_", "").replace("_", " ").strip()
        if label:
            dynamic_pairs.append((label, f"{label}: {value}"))
        else:
            dynamic_pairs.append(("", value))

    custom_priority = {"projet": 0, "objet": 1, "client": 2, "livrable": 3, "beneficiaire": 4, "contrainte": 5}
    dynamic_pairs.sort(key=lambda item: (custom_priority.get(_norm(item[0]), 20), len(item[1])))

    for _, item in dynamic_pairs[:2]:
        if item not in fragments:
            fragments.append(item)

    if details.get("ai_lieu_depart_actuel") and details.get("ai_lieu_souhaite"):
        route = f"{details['ai_lieu_depart_actuel']} vers {details['ai_lieu_souhaite']}"
        if route not in fragments:
            fragments.append(route)

    return fragments[:3]


def _autre_build_title(prompt, details, intents):
    source = _normalize_ws(prompt)
    details = details if isinstance(details, dict) else {}
    intent_names = [intent for intent, confidence in (intents or []) if confidence > 0.1]
    lowered = _norm(source)

    if details.get("ai_lieu_depart_actuel") and details.get("ai_lieu_souhaite"):
        if details.get("ai_nom_formation"):
            return _sentence_case(
                f"Transport de {details['ai_lieu_depart_actuel']} vers {details['ai_lieu_souhaite']} pour formation {details['ai_nom_formation']}"
            )
        return _sentence_case(f"Transport de {details['ai_lieu_depart_actuel']} vers {details['ai_lieu_souhaite']}")

    if details.get("ai_nom_formation"):
        prefix = "Demande de certification" if details.get("ai_type_formation") == "Certification" or _has_word(lowered, "certification") else "Demande de formation"
        return _sentence_case(f"{prefix} {details['ai_nom_formation']}")

    if details.get("ai_montant"):
        return _sentence_case(f"Demande financiere de {details['ai_montant']}")

    if details.get("ai_systeme_concerne"):
        return _sentence_case(f"Demande d acces {details['ai_systeme_concerne']}")

    if details.get("ai_salle_souhaitee"):
        return _sentence_case(f"Reservation de salle {details['ai_salle_souhaitee']}")

    if details.get("ai_zone_souhaitee"):
        return _sentence_case(f"Demande de parking {details['ai_zone_souhaitee']}")

    if details.get("ai_type_contrat"):
        return _sentence_case(f"Demande de {details['ai_type_contrat']}")

    if details.get("ai_poste_souhaite"):
        return _sentence_case(f"Demande de changement de poste vers {details['ai_poste_souhaite']}")

    if details.get("ai_materiel_concerne"):
        return _sentence_case(f"Demande de materiel {details['ai_materiel_concerne']}")

    if details.get("ai_type_conge"):
        return _sentence_case(f"Demande de {details['ai_type_conge']}")

    head = _autre_title_head(intent_names, details, lowered)
    fragments = _autre_title_fragments(details)
    if fragments:
        if head in fragments[0]:
            return _sentence_case(" - ".join(fragments[:2]))
        return _sentence_case(" - ".join([head] + fragments[:2]))

    tokens = [token for token in re.split(r"\s+", source) if token]
    content = [token for token in tokens if _norm(token) not in {"je", "veux", "voudrais", "souhaite", "demande", "une", "un", "de", "du", "des", "la", "le", "les", "pour", "afin", "car", "avec", "et", "ou", "qui", "que"}]
    return _sentence_case(f"{head} - {' '.join(content[:6])}") if content else _sentence_case(source or head)


def _autre_build_description(prompt, title):
    description = _sentence_case(_normalize_ws(prompt))
    if description:
        return description
    return _sentence_case(title)


# ═══════════════════════════════════════════════════════════════
# MONTH ALIASES
# ═══════════════════════════════════════════════════════════════

MONTH_ALIASES = {}
_MONTH_FULL = {
    "janvier": 1, "fevrier": 2, "mars": 3, "avril": 4,
    "mai": 5, "juin": 6, "juillet": 7, "aout": 8,
    "septembre": 9, "octobre": 10, "novembre": 11, "decembre": 12,
}
_MONTH_ABBREV = {
    1: ["janv", "jan"],
    2: ["fevr", "fev", "fevr"],
    3: [],
    4: ["avr"],
    5: [],
    6: [],
    7: ["juil", "juill"],
    8: [],
    9: ["sept", "sep"],
    10: ["oct"],
    11: ["nov"],
    12: ["dec"],
}
for full, num in _MONTH_FULL.items():
    MONTH_ALIASES[full] = num
    accented = full.replace("e", "é").replace("u", "û")
    if accented != full:
        MONTH_ALIASES[accented] = num
    for abbr in _MONTH_ABBREV.get(num, []):
        MONTH_ALIASES[abbr] = num
        MONTH_ALIASES[abbr + "."] = num
        accented_abbr = abbr.replace("e", "é")
        if accented_abbr != abbr:
            MONTH_ALIASES[accented_abbr] = num
            MONTH_ALIASES[accented_abbr + "."] = num

MONTH_PATTERN_INNER = "|".join(
    sorted(
        (re.escape(k) for k in MONTH_ALIASES),
        key=lambda x: -len(x),
    )
)
MONTH_PATTERN = rf"({MONTH_PATTERN_INNER})"


def _normalize_month_token(token):
    return _norm(token).replace(".", "").strip()


# ═══════════════════════════════════════════════════════════════
# AUTO-CORRECTION
# ═══════════════════════════════════════════════════════════════

CORRECTION_MAP = {
    r"\b(?:bjr|bnjr|bonjr|bnjour)\b": "bonjour",
    r"\b(?:slt|saltt?|salu)\b": "salut",
    r"\b(?:stp|svp|sil vous plait)\b": "s il vous plait",
    r"\b(?:jvux|jveux|j veu|jvx|je vx)\b": "je veux",
    r"\b(?:jvoudrai|jvoudrais|je voudr)\b": "je voudrais",
    r"\b(?:souhete|souaite|sohaite|sohait)\b": "souhaite",
    r"\b(?:dqnde|deqnde|demde|dmande|demnde|demnd)\b": "demande",
    r"\b(?:besion|bsoin|beoin)\b": "besoin",
    r"\b(?:conje|congee|conhe|cng)\b": "conge",
    r"\b(?:maladi|maldie|maladdie)\b": "maladie",
    r"\b(?:formtion|fomation|foramtion|formatin)\b": "formation",
    r"\b(?:deplacemnt|deplacment|deplcement|deplasemnt|deplacementt)\b": "deplacement",
    r"\b(?:clam|clame|calm)(?!\w)\b": "calme",
    r"\b(?:bureua|burau|bureu)\b": "bureau",
    r"\bbureaua\b": "bureau a",
    r"\b(?:travai|travil|trvail)\b": "travail",
    r"\b(?:importan|imortant|imporant)\b": "important",
    r"\b(?:tele\s*travail|tele-travail)\b": "teletravail",
    r"\b(?:remboursment|remboursemnt|remboursmnt)\b": "remboursement",
    r"\b(?:acces\s*syteme|acces\s*system|acess\s*systeme)\b": "acces systeme",
    r"\b(?:ordiateur|ordi(?!\w))\b": "ordinateur",
    r"\b(?:pb|problm)\b": "probleme",
    r"\b(?:msg|mssg)\b": "message",
    r"\b(?:rdv)\b": "rendez-vous",
    r"\b(?:certif)\b": "certification",
    r"\b(?:asap)\b": "des que possible",
    r"\b(?:jan|janv\.?)(?!\w)\b": "janvier",
    r"\b(?:fev|fevr|fevr\.?)(?!\w)\b": "fevrier",
    r"\b(?:avr|avr\.)(?!\w)\b": "avril",
    r"\b(?:juil|juill\.?)(?!\w)\b": "juillet",
    r"\b(?:sept|sep\.?)(?!\w)\b": "septembre",
    r"\b(?:oct|oct\.)(?!\w)\b": "octobre",
    r"\b(?:nov|nov\.)(?!\w)\b": "novembre",
    r"\b(?:dec|dec\.)(?!\w)\b": "decembre",
    r"\b(?:info)(?!\w)\b": "information",
    r"\b(?:infos)(?!\w)\b": "informations",
    r"\b(?:pls)(?!\w)\b": "s il vous plait",
}


def _collect_dynamic_vocabulary(samples):
    vocabulary = set()
    for sample in samples or []:
        if not isinstance(sample, dict):
            continue
        prompt = str(sample.get("prompt", "") or "").strip()
        if prompt:
            vocabulary.update(re.findall(r"[A-Za-z][A-Za-z0-9/+#\.-]{2,}", prompt))

        general = sample.get("general") or {}
        if isinstance(general, dict):
            for value in general.values():
                if not isinstance(value, str):
                    continue
                vocabulary.update(re.findall(r"[A-Za-z][A-Za-z0-9/+#\.-]{2,}", value))

        details = sample.get("details") or {}
        if isinstance(details, dict):
            for value in details.values():
                if not isinstance(value, str):
                    continue
                vocabulary.update(re.findall(r"[A-Za-z][A-Za-z0-9/+#\.-]{2,}", value))

    return {token for token in vocabulary if len(token) >= 3}


def _apply_dynamic_token_corrections(text, domain_terms=None):
    terms = sorted({
        _normalize_ws(str(term))
        for term in (domain_terms or [])
        if isinstance(term, str) and len(_normalize_ws(term)) >= 3
    })
    if not terms:
        return text

    normalized_lookup = {}
    for term in terms:
        normalized_lookup.setdefault(_norm(term), term)

    def replace_token(match):
        token = match.group(0)
        normalized = _norm(token)
        if len(normalized) < 4 or normalized.isdigit():
            return token
        if normalized in normalized_lookup:
            return normalized_lookup[normalized]

        candidates = get_close_matches(normalized, list(normalized_lookup.keys()), n=1, cutoff=0.88)
        if not candidates:
            return token

        corrected = normalized_lookup[candidates[0]]
        if token.isupper():
            return corrected.upper()
        if token[:1].isupper():
            return corrected[:1].upper() + corrected[1:]
        return corrected.lower()

    return re.sub(r"\b[A-Za-z][A-Za-z0-9/+#\.-]{3,}\b", replace_token, text)


def _protect_autocorrected_date_prefixes(text):
    protected_phrases = [
        "des le",
        "a partir du",
        "a partir de",
        "des demain",
        "des aujourd hui",
        "jusqu au",
        "jusqu a",
    ]

    protected = _normalize_ws(str(text or ""))
    placeholders = {}
    for index, phrase in enumerate(protected_phrases):
        token = f"__DATE_PREFIX_{index}__"
        pattern = rf"\b{re.escape(phrase)}\b"
        if re.search(pattern, protected, flags=re.IGNORECASE):
            protected = re.sub(pattern, token, protected, flags=re.IGNORECASE)
            placeholders[token] = phrase
    return protected, placeholders


def _restore_autocorrected_date_prefixes(text, placeholders):
    restored = str(text or "")
    for token, phrase in (placeholders or {}).items():
        restored = restored.replace(token, phrase)
    return restored


def _auto_correct_text(text, domain_terms=None):
    cleaned = _normalize_ws(str(text or ""))
    if not cleaned:
        return ""
    cleaned, placeholders = _protect_autocorrected_date_prefixes(cleaned)
    normalized = _norm(cleaned)
    for pattern, replacement in CORRECTION_MAP.items():
        normalized = re.sub(pattern, replacement, normalized, flags=re.IGNORECASE)
    normalized = _apply_dynamic_token_corrections(normalized, domain_terms)
    normalized = re.sub(
        r"\bje\s+veux\s+une\s+demande\s+de\s+de\b",
        "je veux une demande de",
        normalized,
    )
    normalized = re.sub(r"\b(\w+)\s+\1\b", r"\1", normalized)
    normalized = _restore_autocorrected_date_prefixes(normalized, placeholders)
    return _sentence_case(normalized)


# ═══════════════════════════════════════════════════════════════
# STOPWORDS & TOKENIZATION
# ═══════════════════════════════════════════════════════════════

STOPWORDS = {
    "je", "tu", "il", "elle", "nous", "vous", "ils", "elles",
    "de", "du", "des", "la", "le", "les", "un", "une",
    "et", "ou", "a", "au", "aux", "en", "pour", "par",
    "avec", "sur", "dans", "que", "qui", "quoi", "quand",
    "comment", "svp", "stp", "bonjour", "salut", "merci",
    "demande", "besoin", "souhaite", "voudrais", "veux",
    "faire", "avoir", "mon", "ma", "mes", "ton", "ta", "tes",
    "son", "sa", "ses", "ce", "cet", "cette", "ces", "me",
    "te", "se", "si", "ne", "pas", "plus", "tres", "bien",
    "aussi", "car", "donc", "or", "ni", "mais", "est", "sont",
    "ai", "as", "avons", "avez", "ont", "vais", "va", "allons",
    "allez", "vont", "etais", "etait", "sera", "serait",
}


def _tokenize(text, use_bigrams=True):
    normalized = _norm(text)
    if not normalized:
        return []
    raw = [t for t in re.split(r"[^a-z0-9]+", normalized) if len(t) >= 2]
    content = [t for t in raw if t not in STOPWORDS]
    if not use_bigrams:
        return content
    bigrams = [
        content[i] + "_" + content[i + 1] for i in range(len(content) - 1)
    ]
    return content + bigrams


def _tokenize_raw(text):
    normalized = _norm(text)
    if not normalized:
        return []
    return [t for t in re.split(r"[^a-z0-9]+", normalized) if len(t) >= 2]


# ═══════════════════════════════════════════════════════════════
# TF-IDF
# ═══════════════════════════════════════════════════════════════

def _compute_tfidf_weights(samples, text_key="text"):
    num_docs = len(samples)
    if num_docs == 0:
        return {}
    doc_freq = Counter()
    for sample in samples:
        text = str(sample.get(text_key, "") or "")
        for tok in set(_tokenize_raw(text)):
            doc_freq[tok] += 1
    return {
        tok: math.log((num_docs + 1) / (df + 1)) + 1.0
        for tok, df in doc_freq.items()
    }


# ═══════════════════════════════════════════════════════════════
# NAIVE BAYES WITH TF-IDF
# ═══════════════════════════════════════════════════════════════

def _train_tfidf_nb(samples, label_key, text_key="text"):
    idf = _compute_tfidf_weights(samples, text_key)
    labels = []
    doc_count = Counter()
    token_weight = defaultdict(Counter)
    total_weight = Counter()
    vocabulary = set()

    for sample in samples:
        label = str(sample.get(label_key, "") or "").strip()
        text = str(sample.get(text_key, "") or "")
        if not label or not text.strip():
            continue
        labels.append(label)
        doc_count[label] += 1
        raw = _tokenize_raw(text)
        tf = Counter(raw)
        doc_len = max(1, len(raw))
        for tok in _tokenize(text, use_bigrams=True):
            base = tok.split("_")[0] if "_" in tok else tok
            w = (tf.get(base, 1) / doc_len) * idf.get(base, 1.0)
            token_weight[label][tok] += w
            total_weight[label] += w
            vocabulary.add(tok)

    if not labels:
        return None
    return {
        "labels": sorted(set(labels)),
        "doc_count": doc_count,
        "token_weight": token_weight,
        "total_weight": total_weight,
        "vocabulary": vocabulary,
        "num_docs": len(labels),
        "idf": idf,
    }


def _calibrate_confidence(raw, num_docs, num_classes):
    if num_docs >= 500:
        return raw
    decay = max(0.0, 0.30 * (1.0 - (num_docs - 20) / 480))
    floor = 1.0 / max(1, num_classes)
    return max(floor, min(0.97, raw * (1.0 - decay)))


def _predict_tfidf_nb(model, text):
    if model is None:
        return "", 0.0, {}
    tokens = _tokenize(text, use_bigrams=True)
    if not tokens:
        return "", 0.0, {}
    vocab_size = max(1, len(model["vocabulary"]))
    idf = model["idf"]
    raw = _tokenize_raw(text)
    tf = Counter(raw)
    doc_len = max(1, len(raw))
    scores = {}

    for label in model["labels"]:
        prior = (model["doc_count"][label] + 1.0) / (
            model["num_docs"] + len(model["labels"])
        )
        log_prob = math.log(prior)
        tw = model["total_weight"][label]
        for tok in tokens:
            base = tok.split("_")[0] if "_" in tok else tok
            qw = (tf.get(base, 1) / doc_len) * idf.get(base, 1.0)
            stored = model["token_weight"][label].get(tok, 0.0)
            cond = (stored + 1.0) / (tw + vocab_size)
            log_prob += qw * math.log(cond)
        scores[label] = log_prob

    if not scores:
        return "", 0.0, {}
    best = max(scores, key=scores.get)
    mx = max(scores.values())
    exp_s = {l: math.exp(v - mx) for l, v in scores.items()}
    s = sum(exp_s.values())
    probs = {l: (exp_s[l] / s if s > 0 else 0.0) for l in scores}
    conf = _calibrate_confidence(
        probs.get(best, 0.0), model["num_docs"], len(model["labels"])
    )
    return best, conf, probs


# ═══════════════════════════════════════════════════════════════
# AVERAGED PERCEPTRON
# ═══════════════════════════════════════════════════════════════

class AveragedPerceptron:
    def __init__(self):
        self.weights = defaultdict(lambda: defaultdict(float))
        self._totals = defaultdict(lambda: defaultdict(float))
        self._step = 0
        self.labels = []

    def _features(self, text, nb_probs):
        feats = {}
        for tok in set(_tokenize(text, use_bigrams=True)):
            feats[f"t_{tok}"] = 1.0
        for l, p in nb_probs.items():
            feats[f"nb_{l}"] = p
            feats[f"top_{l}"] = 1.0 if (nb_probs and p == max(nb_probs.values())) else 0.0
        return feats

    def _score(self, feats, label):
        return sum(self.weights[label].get(f, 0.0) * v for f, v in feats.items())

    def train(self, samples, nb_model, label_key, text_key="text", epochs=5):
        self.labels = nb_model["labels"] if nb_model else []
        if not self.labels or not samples:
            return
        pairs = []
        for s in samples:
            lbl = str(s.get(label_key, "") or "").strip()
            txt = str(s.get(text_key, "") or "")
            if not lbl or not txt.strip():
                continue
            _, _, probs = _predict_tfidf_nb(nb_model, txt)
            pairs.append((self._features(txt, probs), lbl))

        for ep in range(epochs):
            random.Random(42 + ep).shuffle(pairs)
            for feats, true in pairs:
                scores = {l: self._score(feats, l) for l in self.labels}
                pred = max(scores, key=scores.get) if scores else ""
                if pred != true:
                    self._step += 1
                    for f, v in feats.items():
                        self.weights[true][f] += v
                        self._totals[true][f] += v * self._step
                        self.weights[pred][f] -= v
                        self._totals[pred][f] -= v * self._step
        if self._step > 0:
            for l in self.weights:
                for f in self.weights[l]:
                    self.weights[l][f] = self._totals[l].get(f, 0.0) / self._step

    def predict(self, text, nb_probs):
        if not self.labels:
            return "", {}
        feats = self._features(text, nb_probs)
        scores = {l: self._score(feats, l) for l in self.labels}
        if not scores:
            return "", {}
        mx = max(scores.values())
        exp_s = {l: math.exp(v - mx) for l, v in scores.items()}
        s = sum(exp_s.values())
        probs = {l: (exp_s[l] / s if s > 0 else 0.0) for l in scores}
        return max(probs, key=probs.get), probs


# ═══════════════════════════════════════════════════════════════
# TAXONOMY — keyword maps for scoring
# ═══════════════════════════════════════════════════════════════

TYPE_KEYWORDS = {
    "conge": ["conge", "vacance", "absence", "repos", "maladie", "arret"],
    "attestation de travail": ["attestation", "travail"],
    "attestation de salaire": ["attestation", "salaire"],
    "certificat de travail": ["certificat", "travail"],
    "mutation": ["mutation", "transfert", "affectation"],
    "demission": ["demission", "depart", "quitter"],
    "avance sur salaire": ["avance", "salaire", "paie", "acompte"],
    "remboursement": ["rembourse", "facture", "frais", "depense"],
    "materiel informatique": ["ordinateur", "pc", "laptop", "ecran", "clavier", "souris", "imprimante"],
    "acces systeme": ["acces", "compte", "permission", "erp", "crm", "vpn", "login"],
    "logiciel": ["logiciel", "licence", "application", "software"],
    "probleme technique": ["bug", "panne", "reseau", "wifi", "erreur", "bloque", "connexion"],
    "teletravail": ["teletravail", "distance", "remote", "domicile"],
    "heures supplementaires": ["heures supplementaires", "heure sup", "overtime"],
    "formation interne": ["formation interne", "atelier interne"],
    "formation externe": ["formation externe", "formation", "stage", "seminaire"],
    "certification": ["certification", "certif", "examen", "diplome"],
    "transport": ["transport", "vehicule", "navette", "deplacement", "trajet"],
}

GENERAL_TYPE_TAXONOMY = {
    "it": {
        "acces systeme": ["acces", "permission", "compte", "erp", "crm", "vpn", "login", "droit", "habilitation"],
        "incident technique": ["bug", "panne", "erreur", "down", "indisponible", "bloque", "connexion", "lent", "serveur"],
        "materiel informatique": ["ordinateur", "pc", "laptop", "ecran", "clavier", "souris", "imprimante", "scanner"],
        "logiciel": ["logiciel", "application", "licence", "installation", "software"],
        "teletravail": ["teletravail", "remote", "domicile", "a distance"],
    },
    "rh": {
        "conge maladie": ["conge", "maladie", "medical", "arret", "sante", "hospitalisation"],
        "conge annuel": ["conge", "vacance", "repos", "annuel", "conges payes"],
        "attestation": ["attestation", "certificat", "justificatif"],
        "paie": ["paie", "salaire", "bulletin", "avance", "prime", "acompte"],
        "formation": ["formation", "certification", "examen", "atelier", "seminaire", "stage"],
        "mutation": ["mutation", "transfert", "mobilite", "affectation"],
        "demission": ["demission", "depart", "quitter", "preavis"],
        "heures supplementaires": ["heures supplementaires", "overtime", "heure sup"],
    },
    "logistique": {
        "transport": ["transport", "vehicule", "navette", "deplacement", "trajet", "voyage", "mission"],
        "espace travail": ["bureau", "espace", "casier", "chaise", "eclairage", "parking"],
        "fournitures": ["fourniture", "papier", "stylo", "consommable", "cartouche"],
        "maintenance": ["maintenance", "reparation", "climatisation", "serrure", "chauffage"],
    },
    "finance": {
        "remboursement frais": ["remboursement", "frais", "facture", "depense", "note de frais"],
        "avance sur salaire": ["avance", "salaire", "acompte"],
    },
    "securite": {
        "incident securite": ["securite", "fuite", "piratage", "phishing", "cyberattaque", "virus", "malware"],
    },
}


# ═══════════════════════════════════════════════════════════════
# DECISION MATRIX — Priority inference
# ═══════════════════════════════════════════════════════════════

# Each signal has a weight vector across priority levels:
# [LOW, MEDIUM, HIGH, CRITICAL]
# Higher weight = stronger pull toward that priority.

PRIORITY_SIGNAL_MATRIX = {
    # keyword signals
    "kw_critical": {
        "keywords": [
            "urgence medicale", "hospitalisation", "accident", "incendie",
            "piratage", "cyberattaque", "fuite donnees", "bloquant total",
            "serveur down", "intrusion", "ransomware",
        ],
        "weights": [0.0, 0.0, 0.1, 0.9],
    },
    "kw_high": {
        "keywords": [
            "urgent", "urgence", "bloquant", "au plus vite", "immediat",
            "impossible travailler", "bloque", "indisponible",
        ],
        "weights": [0.0, 0.05, 0.85, 0.1],
    },
    "kw_low": {
        "keywords": [
            "pas urgent", "quand possible", "faible", "confort",
            "amelioration", "souhait", "preference", "basse priorite",
        ],
        "weights": [0.85, 0.1, 0.05, 0.0],
    },

    # type signals — certain types have natural priority tendencies
    "type_securite": {
        "condition": lambda ctx: ctx.get("inferred_type") == "securite",
        "weights": [0.0, 0.05, 0.25, 0.7],
    },
    "type_incident": {
        "condition": lambda ctx: ctx.get("inferred_subtype") in ("incident technique", "incident securite"),
        "weights": [0.0, 0.1, 0.6, 0.3],
    },
    "type_conge_maladie": {
        "condition": lambda ctx: ctx.get("inferred_subtype") == "conge maladie",
        "weights": [0.0, 0.15, 0.7, 0.15],
    },
    "type_fournitures": {
        "condition": lambda ctx: ctx.get("inferred_subtype") == "fournitures",
        "weights": [0.6, 0.35, 0.05, 0.0],
    },
    "type_espace_travail": {
        "condition": lambda ctx: ctx.get("inferred_subtype") == "espace travail",
        "weights": [0.5, 0.4, 0.1, 0.0],
    },

    # structural signals
    "has_deadline_soon": {
        "condition": lambda ctx: ctx.get("days_until_deadline") is not None and ctx["days_until_deadline"] <= 3,
        "weights": [0.0, 0.05, 0.8, 0.15],
    },
    "has_deadline_medium": {
        "condition": lambda ctx: ctx.get("days_until_deadline") is not None and 3 < ctx["days_until_deadline"] <= 14,
        "weights": [0.05, 0.7, 0.2, 0.05],
    },
    "has_large_amount": {
        "condition": lambda ctx: ctx.get("amount") is not None and ctx["amount"] > 1000,
        "weights": [0.0, 0.2, 0.7, 0.1],
    },
    "has_exclamation": {
        "condition": lambda ctx: "!" in ctx.get("raw_text", ""),
        "weights": [0.0, 0.2, 0.6, 0.2],
    },
}

PRIORITY_LEVELS = ["LOW", "MEDIUM", "HIGH", "CRITICAL"]


def _compute_priority_via_matrix(corrected_text, context):
    """
    Decision matrix: each matched signal contributes a weighted vote
    across [LOW, MEDIUM, HIGH, CRITICAL]. Final priority = argmax of
    accumulated votes.
    """
    normalized = _norm(corrected_text)
    votes = [0.0, 0.0, 0.0, 0.0]  # LOW, MEDIUM, HIGH, CRITICAL

    # Default prior: slightly favor MEDIUM
    votes[1] += 0.3

    for signal_name, signal in PRIORITY_SIGNAL_MATRIX.items():
        activated = False

        # Keyword-based activation
        if "keywords" in signal:
            for kw in signal["keywords"]:
                if _has_phrase(normalized, kw):
                    activated = True
                    break

        # Condition-based activation
        if "condition" in signal and not activated:
            try:
                activated = signal["condition"](context)
            except Exception:
                activated = False

        if activated:
            weights = signal["weights"]
            for i in range(4):
                votes[i] += weights[i]

    best_idx = votes.index(max(votes))
    total = sum(votes)
    confidence = votes[best_idx] / total if total > 0 else 0.25

    return PRIORITY_LEVELS[best_idx], round(confidence, 3), {
        PRIORITY_LEVELS[i]: round(votes[i] / total, 3) if total > 0 else 0.25
        for i in range(4)
    }


# ═══════════════════════════════════════════════════════════════
# DYNAMIC NAMED ENTITY RECOGNITION
# ═══════════════════════════════════════════════════════════════

# Instead of hardcoded city lists, detect entities by position/pattern

ENTITY_NOISE_WORDS = {
    "demande", "besoin", "formation", "transport", "acces",
    "systeme", "travail", "distance", "conge", "attestation",
    "remboursement", "avance", "salaire", "probleme", "technique",
    "logiciel", "materiel", "informatique", "maintenance",
    "fourniture", "certificat", "mutation", "teletravail",
    "heures", "supplementaires", "certification", "securite",
    "urgent", "urgence", "possible", "souhaite", "veux",
    "voudrais", "besoin", "merci", "bonjour", "salut",
    "type", "moyen", "vehicule", "navette", "deplacement",
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

EXPLICIT_BENEFICIARY_BLOCKLIST = {
    "taxi",
    "train",
    "bus",
    "avion",
    "vol",
    "voiture",
    "vehicule",
    "transport",
    "mission",
    "client",
    "clients",
    "fournisseur",
    "fournisseurs",
    "societe",
    "entreprise",
    "partenaire",
}

ENTITY_NOISE_WORDS |= NON_LOCATION_NOISE_WORDS


def _is_likely_proper_noun(word):
    """
    Heuristic: a word is likely a proper noun (city, name, etc.) if:
    - It's not in our noise words
    - It's >= 3 chars
    - It doesn't look like a common French word pattern
    """
    n = _norm(word)
    if n in ENTITY_NOISE_WORDS:
        return False
    if n in STOPWORDS:
        return False
    if len(n) < 3:
        return False
    # Months are not proper nouns
    if n in MONTH_ALIASES:
        return False
    # Numbers are not proper nouns
    if re.match(r"^\d+$", n):
        return False
    return True


def _extract_location_pair(corrected_text):
    """
    Dynamic location extraction: finds "de X vers Y" patterns
    and applies proper noun heuristics. No hardcoded city list.
    """
    lowered = _norm(corrected_text)

    patterns = [
        r"\bde\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\b",
        r"\bdepuis\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\b",
        r"\bdepart\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b.*?\b(?:destination|arrivee)\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b",
    ]

    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if match:
            raw_dep = _normalize_ws(match.group(1))
            raw_dest = _normalize_ws(match.group(2))

            # Check both are likely proper nouns
            dep_words = raw_dep.split()
            dest_words = raw_dest.split()

            dep_valid = any(_is_likely_proper_noun(w) for w in dep_words)
            dest_valid = any(_is_likely_proper_noun(w) for w in dest_words)

            if dep_valid and dest_valid and _norm(raw_dep) != _norm(raw_dest):
                dep = _capitalize_entity(raw_dep)
                dest = _capitalize_entity(raw_dest)
                return dep, dest

    return None, None


def _extract_destination_hint(corrected_text):
    """
    Extract a destination hint from free-form phrasing like
    "... formation ... a/à Hammam-Lif ..." when no explicit route exists.
    """
    lowered = _norm(corrected_text)
    if not lowered:
        return None

    patterns = [
        r"\b(?:vers|destination\s*[:\-]?)\s+([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,3})(?=\s+(?:qui|que|le|du|de|pour|afin|car|avec|des|a\s+partir|depuis|jusqu)\b|[\.,;]|$)",
        r"\b(?:a|à|vers|chez)\s+(?!partir\b|compter\b)([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,3})(?=\s+(?:qui|que|le|du|de|pour|afin|car|avec|des|a\s+partir|depuis|jusqu|debut|debute|commence)\b|[\.,;]|$)",
    ]

    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        candidate = _normalize_ws(match.group(1)).strip(" ,;:-")
        candidate = re.sub(r"\b(?:qui|que|dont)\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"\b(?:debut|debute|commence)\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        if not candidate:
            continue
        if _norm(candidate) in NON_LOCATION_NOISE_WORDS:
            continue
        words = candidate.split()
        if not all(_is_likely_proper_noun(word) for word in words):
            continue
        return _capitalize_entity(candidate)

    return None


def _capitalize_entity(value):
    """
    Smart capitalization for entity names.
    'hammam lif' → 'Hammam Lif'
    'tunis' → 'Tunis'
    """
    if not value:
        return None
    parts = _normalize_ws(value).split()
    return " ".join(p[:1].upper() + p[1:] for p in parts)


# ═══════════════════════════════════════════════════════════════
# DATE EXTRACTION
# ═══════════════════════════════════════════════════════════════

def _parse_date_candidate(day, month, year):
    try:
        dt = datetime(int(year), int(month), int(day))
        return dt.strftime("%Y-%m-%d")
    except ValueError:
        return None


def _shift_date_by_months(base_date, months):
    try:
        month_index = base_date.month - 1 + int(months)
        year = base_date.year + (month_index // 12)
        month = (month_index % 12) + 1
        day = min(base_date.day, monthrange(year, month)[1])
        return base_date.replace(year=year, month=month, day=day)
    except ValueError:
        return base_date


def _extract_relative_duration_dates(text):
    lowered = _norm(str(text or ""))
    if not lowered:
        return None, None

    today = datetime.now().date()
    duration_match = re.search(r"\b(?:pendant|pour|durant|sur)\s+(\d+|un|une)\s+(jour|jours|mois)\b", lowered, flags=re.IGNORECASE)
    if not duration_match:
        return None, None

    raw_amount = duration_match.group(1)
    amount = 1 if raw_amount in {"un", "une"} else int(raw_amount)
    unit = duration_match.group(2)

    start_date = today
    if unit == "jour" or unit == "jours":
        end_date = today + timedelta(days=amount)
    else:
        end_date = _shift_date_by_months(today, amount)

    return start_date.isoformat(), end_date.isoformat()


def _extract_date_range(text):
    normalized_text = str(text or "")
    extracted = []
    current_dt = datetime.now()
    lowered = _norm(normalized_text)

    duration_start, duration_end = _extract_relative_duration_dates(normalized_text)
    if duration_start:
        extracted.append(duration_start)
        if duration_end:
            extracted.append(duration_end)

    if re.search(r"\b(?:aujourd['’ ]hui|ce\s+jour)\b", lowered, flags=re.IGNORECASE):
        extracted.append(current_dt.strftime("%Y-%m-%d"))
    if re.search(r"\b(?:demain|des\s+demain)\b", lowered, flags=re.IGNORECASE):
        extracted.append((current_dt + timedelta(days=1)).strftime("%Y-%m-%d"))
    if re.search(r"\b(?:apres\s+demain|apr[eè]s\s+demain)\b", lowered, flags=re.IGNORECASE):
        extracted.append((current_dt + timedelta(days=2)).strftime("%Y-%m-%d"))

    relative_day_match = re.search(
        r"\b(?:dans|d['’]ici|sous)\s+(\d+|un|une)\s+jours?\b",
        lowered,
        flags=re.IGNORECASE,
    )
    if relative_day_match:
        raw_days = relative_day_match.group(1)
        days = 1 if raw_days in {"un", "une"} else int(raw_days)
        extracted.append((current_dt + timedelta(days=days)).strftime("%Y-%m-%d"))

    relative_month_match = re.search(
        r"\b(?:dans|d['’]ici|sous)\s+(\d+|un|une)\s+mois\b",
        lowered,
        flags=re.IGNORECASE,
    )
    if relative_month_match:
        raw_months = relative_month_match.group(1)
        months = 1 if raw_months in {"un", "une"} else int(raw_months)
        extracted.append(_shift_date_by_months(current_dt.date(), months).isoformat())

    months_later_match = re.search(
        r"\b(\d+|un|une)?\s*mois\s+(?:plus\s+tard|apres|apr[eè]s|plus\s+loin)\b",
        lowered,
        flags=re.IGNORECASE,
    )
    if months_later_match:
        raw_months = months_later_match.group(1)
        months = 1 if not raw_months or raw_months in {"un", "une"} else int(raw_months)
        extracted.append(_shift_date_by_months(current_dt.date(), months).isoformat())

    for m in re.finditer(r"\b(\d{4})-(\d{2})-(\d{2})\b", normalized_text):
        p = _parse_date_candidate(m.group(3), m.group(2), m.group(1))
        if p:
            extracted.append(p)

    for m in re.finditer(r"\b(\d{1,2})[/-](\d{1,2})[/-](\d{4})\b", normalized_text):
        p = _parse_date_candidate(m.group(1), m.group(2), m.group(3))
        if p:
            extracted.append(p)

    for m in re.finditer(r"\b(\d{1,2})[/-](\d{1,2})\b", normalized_text):
        day = int(m.group(1))
        month = int(m.group(2))
        year = current_dt.year
        if (month, day) < (current_dt.month, current_dt.day):
            year += 1
        p = _parse_date_candidate(day, month, year)
        if p:
            extracted.append(p)

    lowered = _norm(normalized_text)
    for m in re.finditer(
        rf"\b(\d{{1,2}})\s+{MONTH_PATTERN}\s+(\d{{4}})\b",
        lowered,
        flags=re.IGNORECASE,
    ):
        day = int(m.group(1))
        month_tok = _normalize_month_token(m.group(2))
        month_num = MONTH_ALIASES.get(month_tok)
        year = int(m.group(3))
        if month_num:
            p = _parse_date_candidate(day, month_num, year)
            if p:
                extracted.append(p)

    for m in re.finditer(
        rf"\b(\d{{1,2}})\s+{MONTH_PATTERN}\b",
        lowered,
        flags=re.IGNORECASE,
    ):
        if re.match(r"^\d{4}$", lowered[m.end():].strip()[:4]):
            continue
        day = int(m.group(1))
        month_tok = _normalize_month_token(m.group(2))
        month_num = MONTH_ALIASES.get(month_tok)
        if not month_num:
            continue
        year = current_dt.year
        if (month_num, day) < (current_dt.month, current_dt.day):
            year += 1
        p = _parse_date_candidate(day, month_num, year)
        if p:
            extracted.append(p)

    if not extracted:
        return None, None
    unique = sorted(set(extracted))
    return unique[0], (unique[1] if len(unique) > 1 else None)


def _days_until(date_str):
    if not date_str:
        return None
    try:
        target = datetime.strptime(date_str, "%Y-%m-%d")
        delta = (target - datetime.now()).days
        return max(0, delta)
    except ValueError:
        return None


def _extract_nombre_jours(text, date_debut, date_fin):
    normalized = _norm(text)
    explicit = re.search(r"\b(\d{1,3})\s+jours?\b", normalized)
    if explicit:
        return int(explicit.group(1))
    if date_debut and date_fin:
        try:
            s = datetime.strptime(date_debut, "%Y-%m-%d")
            e = datetime.strptime(date_fin, "%Y-%m-%d")
            if e >= s:
                return (e - s).days + 1
        except ValueError:
            pass
    return None


# ═══════════════════════════════════════════════════════════════
# ENTITY EXTRACTION — names, amounts, justification
# ═══════════════════════════════════════════════════════════════

def _clean_entity_text(value):
    """
    Strip dates, locations, and noise from an entity value.
    """
    text = _normalize_ws(value)
    if not text:
        return ""
    text = re.sub(r"\b\d{4}-\d{2}-\d{2}\b", "", text)
    text = re.sub(r"\b\d{1,2}[/-]\d{1,2}[/-]\d{4}\b", "", text)
    text = re.sub(
        rf"\b\d{{1,2}}\s+{MONTH_PATTERN}\s+\d{{4}}\b", "", text, flags=re.IGNORECASE,
    )
    text = re.sub(
        rf"\ble\s+\d{{1,2}}\s+{MONTH_PATTERN}\b", "", text, flags=re.IGNORECASE,
    )
    text = re.sub(
        r"\bde\s+[a-zà-ÿ\s]+?\s+vers\s+[a-zà-ÿ\s]+", "", text, flags=re.IGNORECASE,
    )
    text = re.sub(
        r"\b(pour|du|le|a partir du|des le|a compter)\b.*$", "", text, flags=re.IGNORECASE,
    )
    text = re.sub(r"\b(le|du|au)\s+\d+.*$", "", text, flags=re.IGNORECASE)
    return _normalize_ws(text).strip(" ,;:-")


def _extract_subject_name(corrected_text, subject_keyword):
    """
    Dynamic subject name extraction.
    Given keyword (e.g. 'formation'), extracts the name that follows it.
    """
    lowered = _norm(corrected_text)
    stop_tail = r"\s+(?:le|du|de|vers|pour|a|à|dans|qui|que|debut|debute|commence|a\s+partir|depuis|jusqu)\b"
    patterns = [
        rf"\b{re.escape(subject_keyword)}\s+(?:en\s+)?([a-z][a-z0-9\s\-\./+#]+?)(?={stop_tail}|$)",
        rf"\bpour\s+(?:une?\s+)?{re.escape(subject_keyword)}\s+(?:en\s+)?([a-z][a-z0-9\s\-\./+#]+?)(?={stop_tail}|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if match:
            raw = match.group(1).strip()
            cleaned = _clean_entity_text(raw)
            if cleaned and len(cleaned) >= 2 and _is_likely_proper_noun(cleaned.split()[0]):
                return _capitalize_entity(cleaned)
            elif cleaned and len(cleaned) >= 2:
                return cleaned[:1].upper() + cleaned[1:]
    return None


def _extract_amount(text):
    normalized = _norm(str(text or ""))
    patterns = [
        r"\b(\d{1,6}(?:[.,]\d{1,2})?)\s*(?:dinars?|dhs?|euros?|eur|tnd|mad|usd|\$|€|da|dt)\b",
        r"\bmontant\s*(?:de|:)?\s*(\d{1,6}(?:[.,]\d{1,2})?)\b",
        r"\b(?:cout|co[uû]t|prix|somme|valeur|budget)\s*(?:de|:)?\s*(\d{1,6}(?:[.,]\d{1,2})?)\b",
    ]
    for p in patterns:
        m = re.search(p, normalized)
        if m:
            return m.group(1).replace(",", ".")
    return None


def _is_explicit_beneficiary_candidate(value):
    candidate = _clean_entity_text(value)
    if not candidate:
        return ""
    words = [part for part in re.split(r"\s+", candidate) if part]
    if len(words) < 2:
        return ""
    if len(words) > 4:
        words = words[:4]
    for word in words:
        token = _norm(word)
        if not token or token in EXPLICIT_BENEFICIARY_BLOCKLIST:
            return ""
        if not _is_likely_proper_noun(token):
            return ""
    return _capitalize_entity(" ".join(words))


def _extract_explicit_beneficiary(text):
    source = _normalize_ws(text)
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
        candidate = _is_explicit_beneficiary_candidate(match.group(1))
        if candidate:
            return candidate
    return ""


def _extract_justification(text):
    lowered = _norm(_normalize_ws(text))
    patterns = [
        r"(?:car|parce\s+que|suite\s+a|en\s+raison\s+de|afin\s+de)\s+(.{10,}?)(?:\.|$)",
        r"(?:motif|raison)\s*[:\-]?\s*(.{5,}?)(?:\.|$)",
    ]
    for p in patterns:
        m = re.search(p, lowered, flags=re.IGNORECASE)
        if m:
            extracted = _normalize_ws(m.group(1))
            if extracted and len(extracted) >= 5:
                return extracted[:300]
    return None


def _extract_parking_zone(text):
    normalized = _norm(_normalize_ws(text))
    if not normalized:
        return ""

    d_connector = r"(?:d['’]?\s*|de\s+|du\s+|des\s+|de\s+la\s+|de\s+l['’]?\s*)"
    patterns = [
        rf"\b(?:pres\s+{d_connector}|proche\s+{d_connector}|a\s+cote\s+{d_connector}|devant|au\s+niveau\s+{d_connector}|dans\s+la\s+zone\s+{d_connector}|zone\s+{d_connector})(.{{2,90}}?)(?:[.,;]|$)",
        rf"\bparking\s+(?:pres\s+{d_connector}|proche\s+{d_connector}|a\s+cote\s+{d_connector}|devant)\s*(.{{2,90}}?)(?:[.,;]|$)",
    ]

    for pattern in patterns:
        match = re.search(pattern, normalized, flags=re.IGNORECASE)
        if not match:
            continue

        candidate = _normalize_ws(match.group(1))
        candidate = re.sub(r"\b(?:tot|t[oô]t)\s+le\s+matin\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"\b(?:matin|apres\s*midi|soir|nuit)\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"\b(?:a|a\s+partir\s+de)\s+\d{1,2}(?:h|:\d{2})?(?:\s*(?:am|pm|du\s+matin|du\s+soir))?\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"\b(?:le|des|d[èe]s)\s+\d{1,2}(?:er)?(?:\s+[a-z]+)?\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"\b(?:car|parce\s+que|afin\s+de|pour)\b.*$", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"^(?:l\s+|le\s+|la\s+|les\s+|un\s+|une\s+)", "", candidate, flags=re.IGNORECASE).strip()
        candidate = re.sub(r"\bl\s+([a-zà-ÿ])", r"l'\1", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"\bd\s+([a-zà-ÿ])", r"d'\1", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"\bentrer\b", "entree", candidate, flags=re.IGNORECASE)
        candidate = re.sub(r"\bsorti\b", "sortie", candidate, flags=re.IGNORECASE)
        candidate = _normalize_ws(candidate).strip(" ,;:-")

        if len(candidate) >= 2:
            cleaned_candidate = _capitalize_entity(_clean_entity_text(candidate))
            if cleaned_candidate and _has_any_word(_norm(cleaned_candidate), ["pres", "proche", "cote", "devant", "derriere", "loin", "zone", "entree", "sortie", "hall", "batiment", "bloc", "tour", "niveau", "etage", "sous-sol", "souterrain"]):
                return cleaned_candidate

    if _has_word(normalized, "entree"):
        return "Entree principale"

    return ""


def _infer_parking_type(text):
    normalized = _norm(_normalize_ws(text))
    if not normalized:
        return ""
    if _has_word(normalized, "temporaire"):
        return "Autorisation temporaire"
    if _has_any_phrase(normalized, ["acces parking", "badge parking"]):
        return "Acces parking"
    if _has_any_phrase(normalized, ["parking", "stationnement", "place reservee", "place reserve"]):
        return "Place reservee"
    return ""


def _extract_arrival_time(text):
    normalized = _norm(_normalize_ws(text))
    if not normalized:
        return ""

    patterns = [
        r"\b(\d{1,2}(?:[:h]\d{2})?\s*(?:am|pm|du\s+matin|du\s+soir))\b",
        r"\b((?:tot|t[oô]t)\s+le\s+matin)\b",
        r"\b(a\s+partir\s+de\s+\d{1,2}(?:[:h]\d{2})?)\b",
        r"\b(\d{1,2}\s*du\s+matin)\b",
        r"\b(le\s+matin|matin|apres\s*midi|soir|nuit)\b",
    ]

    for pattern in patterns:
        match = re.search(pattern, normalized, flags=re.IGNORECASE)
        if not match:
            continue
        value = _normalize_ws(match.group(1))
        if value:
            return value[:1].upper() + value[1:]

    return ""


def _clean_subject_like_name(value):
    candidate = _normalize_ws(str(value or ""))
    if not candidate:
        return ""

    normalized = _norm(candidate)
    if not normalized:
        return ""

    candidate = re.sub(r"\b(?:du|de|depuis)\s+[a-zà-ÿ\s]{2,40}\s+(?:vers|a|à)\s+[a-zà-ÿ\s]{2,40}\b.*$", "", candidate, flags=re.IGNORECASE).strip()
    candidate = re.sub(r"\b(?:qui|que|dont)\b.*$", "", candidate, flags=re.IGNORECASE).strip()
    candidate = re.sub(r"\s+(?:a|à|au|aux|dans)\s+(?!distance\b|distanciel\b|domicile\b)[a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2}(?=\s+(?:qui|que|le|du|de|pour|afin|car|avec|des|a\s+partir|depuis|jusqu|debut|debute|commence)\b|$)", "", candidate, flags=re.IGNORECASE).strip()
    candidate = re.sub(r"\b(?:debut|debute|a partir de|des|d[èe]s|le)\s+\d{1,2}(?:er)?(?:\s+[a-z]+)?\b.*$", "", candidate, flags=re.IGNORECASE).strip()
    candidate = re.sub(r"\b(?:car|parce\s+que|afin\s+de|pour)\b.*$", "", candidate, flags=re.IGNORECASE).strip()
    candidate = re.sub(r"\s{2,}", " ", candidate).strip(" ,;:-")

    words = candidate.split()
    if len(words) > 6:
        candidate = " ".join(words[:6])

    return candidate


def _trim_route_suffix_from_subject(subject, route_locations):
    candidate = _normalize_ws(subject)
    if not candidate:
        return ""

    for location in route_locations or []:
        location_text = _normalize_ws(location)
        if not location_text:
            continue
        if re.search(rf"\b(?:de|du)\s+{re.escape(location_text)}$", candidate, flags=re.IGNORECASE):
            candidate = re.sub(
                rf"\s+(?:de|du)\s+{re.escape(location_text)}$",
                "",
                candidate,
                flags=re.IGNORECASE,
            ).strip()

    return _normalize_ws(candidate)


def _extract_keywords(text, limit=8):
    normalized = _norm(text)
    tokens = _tokenize(text, use_bigrams=False)
    filtered = [t for t in tokens if t not in STOPWORDS and len(t) >= 3]

    # Keep compact technical/domain tokens often written in short forms.
    short_whitelist = {
        "ui", "ux", "qa", "bi", "ai", "ml", "it", "rh", "crm", "erp", "vpn", "api",
    }
    short_tokens = [tok for tok in _tokenize_raw(text) if tok in short_whitelist]

    # Preserve slash-composed terms from natural prompts (e.g., ui/ux, c/c++, devops/sre).
    slash_terms = [
        _norm(match.group(0)).replace(" ", "/")
        for match in re.finditer(r"\b[a-z0-9]{1,8}\/[a-z0-9+.#-]{1,8}\b", str(text or ""), flags=re.IGNORECASE)
    ]

    counts = Counter(filtered)
    for token in short_tokens:
        counts[token] += 2
    for token in slash_terms:
        counts[token] += 2
    ranked = [t for t, _ in sorted(counts.items(), key=lambda x: (-x[1], x[0]))]
    return ranked[:limit]


# ═══════════════════════════════════════════════════════════════
# SEMANTIC FIELD DEDUPLICATION
# ═══════════════════════════════════════════════════════════════

FIELD_SEMANTIC_GROUPS = {
    "date_souhaitee": [
        "date souhaitee", "date souhaitée", "date", "date debut",
        "date de debut", "datedebut", "date souhaitee pour le transport",
        "date prevue", "date prévue", "date demandee",
    ],
    "date_fin": [
        "date fin", "date de fin", "datefin", "date de retour",
    ],
    "description_detaillee_besoin": [
        "description detaillee du besoin", "description détaillée du besoin",
        "description besoin", "description", "besoin", "detail",
        "description detaillee", "commentaire",
    ],
    "type_formation": [
        "type de formation", "type formation", "categorie formation",
    ],
    "nom_formation": [
        "nom de la formation", "nom formation", "intitule formation",
        "intitulé formation", "formation", "titre formation",
    ],
    "lieu_depart_actuel": [
        "lieu de depart actuel", "lieu de départ actuel",
        "lieu depart", "lieu de depart", "depart", "ville depart",
    ],
    "lieu_souhaite": [
        "lieu souhaite", "lieu souhaité", "destination",
        "lieu darrivee", "lieu d arrivee", "ville arrivee",
        "lieu d'arrivée",
    ],
    "type_transport_souhaite": [
        "type de transport souhaite", "type de transport souhaité",
        "type transport", "moyen de transport", "moyen transport",
    ],
    "montant": [
        "montant", "cout", "coût", "prix", "somme", "valeur",
    ],
    "justification": [
        "justification", "motif", "raison", "explication",
    ],
    "nombre_jours": [
        "nombre jours", "nombre de jours", "duree", "durée",
        "nb jours", "jours",
    ],
}

_CANONICAL_LOOKUP = {}
for canonical, aliases in FIELD_SEMANTIC_GROUPS.items():
    for alias in aliases:
        _CANONICAL_LOOKUP[_norm(alias)] = canonical
    _CANONICAL_LOOKUP[canonical] = canonical


def _canonical_field_key(key):
    n = _norm(key).replace("_", " ").strip()
    if n in _CANONICAL_LOOKUP:
        return _CANONICAL_LOOKUP[n]
    return re.sub(r"[^a-z0-9]+", "_", n).strip("_")


def _dedupe_fields(data):
    """
    Remove duplicate fields by mapping all keys to their canonical form.
    First occurrence wins.
    """
    if not data:
        return {}
    result = {}
    seen = set()
    for raw_key, value in data.items():
        canonical = _canonical_field_key(raw_key)
        if canonical in seen:
            continue
        seen.add(canonical)
        result[canonical] = value
    return result


# ═══════════════════════════════════════════════════════════════
# PROMPT PARSER
# ═══════════════════════════════════════════════════════════════

def _extract_json_value(prompt, label):
    idx = prompt.find(label)
    if idx < 0:
        return None
    segment = prompt[idx + len(label):].lstrip()
    decoder = json.JSONDecoder()
    try:
        value, _ = decoder.raw_decode(segment)
        return value
    except json.JSONDecodeError:
        return None


def _extract_any_json_block(text):
    decoder = json.JSONDecoder()
    for ch in ["{", "["]:
        idx = 0
        while True:
            pos = text.find(ch, idx)
            if pos < 0:
                break
            try:
                val, _ = decoder.raw_decode(text[pos:])
                if isinstance(val, (dict, list)):
                    return val
            except json.JSONDecodeError:
                pass
            idx = pos + 1
    return None


def _extract_text_from_payload(request_data, prompt):
    for k in [
        "input", "text", "rawText", "userInput", "user_input",
        "userText", "texte", "texte_brut", "message", "content",
        "body", "query", "request", "demande_text", "description",
    ]:
        v = request_data.get(k)
        if isinstance(v, str) and v.strip():
            return v.strip()

    for label in [
        "Texte utilisateur brut: ", "Texte: ", "Input: ",
        "Message: ", "Demande: ", "Texte utilisateur: ",
    ]:
        v = _extract_json_value(prompt, label)
        if isinstance(v, str) and v.strip():
            return v.strip()

    for p in [
        r'(?:input|texte|text|message|demande)\s*[:=]\s*["\']?([^"\'\n]{5,})["\']?',
        r'"(?:text|input|texte)"\s*:\s*"([^"]{5,})"',
    ]:
        m = re.search(p, prompt, flags=re.IGNORECASE)
        if m:
            return _normalize_ws(m.group(1))

    blob = _extract_any_json_block(prompt)
    if isinstance(blob, dict):
        for k in ["text", "input", "texte", "message", "description"]:
            v = blob.get(k)
            if isinstance(v, str) and v.strip():
                return v.strip()

    stripped = _normalize_ws(prompt)
    if (
        len(stripped) >= 10
        and not stripped.startswith("{")
        and not stripped.startswith("[")
        and "correctedText" not in stripped
    ):
        return stripped
    return ""


def _extract_categories_and_types(request_data, prompt):
    categories = None
    type_map = None
    for k in ["categories", "Categories", "allowedCategories"]:
        v = request_data.get(k)
        if isinstance(v, list):
            categories = v
            break
    for k in ["typeMap", "type_map", "typesParCategorie", "types"]:
        v = request_data.get(k)
        if isinstance(v, dict):
            type_map = v
            break
    if categories is None:
        categories = _extract_json_value(prompt, "Categories autorisees: ")
    if type_map is None:
        type_map = _extract_json_value(prompt, "Types autorises par categorie: ")
    if not isinstance(categories, list) or not categories:
        categories = ["Autre"]
    if not isinstance(type_map, dict) or not type_map:
        type_map = {str(categories[0]): ["Autre"]}
    return categories, type_map


def _extract_priorities(request_data, prompt):
    for k in ["priorities", "priorites", "allowedPriorities"]:
        v = request_data.get(k)
        if isinstance(v, list):
            return v
    v = _extract_json_value(prompt, "Priorites autorisees: ")
    if isinstance(v, list):
        return v
    return ["HAUTE", "NORMALE", "BASSE"]


# Only curated datasets should be blended with DB data.
APPROVED_EXTERNAL_SOURCES = {
    "bea2019_wi_locness",
    "fce_v21",
    "lang8",
    "nucle",
    "c4_multilingual_curated",
    "mc4_curated",
}


def _is_domain_relevant_text(text):
    normalized = _norm(text)
    if not normalized:
        return False

    # Reject generic web/noise fragments that hurt demand classification.
    if re.search(r"https?://|www\.|<[^>]+>|cookie|subscribe|newsletter|copyright", normalized):
        return False

    domain_keywords = {
        "demande", "besoin", "transport", "formation", "conge", "acces", "compte", "remboursement",
        "avance", "salaire", "attestation", "certificat", "logiciel", "materiel", "ordinateur", "incident",
        "maintenance", "fourniture", "priorite", "urgent", "date", "tunis", "sfax", "sousse",
    }

    tokens = set(_tokenize_raw(normalized))
    hits = len(tokens & domain_keywords)
    return hits >= 1


def _is_valid_training_text(text):
    cleaned = _normalize_ws(text)
    if len(cleaned) < 12 or len(cleaned) > 1200:
        return False

    normalized = _norm(cleaned)
    # Overly repetitive garbage or token explosions.
    if re.search(r"\b(\w+)\s+\1\s+\1\b", normalized):
        return False
    if len(_tokenize_raw(normalized)) < 3:
        return False

    return _is_domain_relevant_text(cleaned)


def _extract_external_training_samples(request_data):
    raw_sources = request_data.get("externalTrainingSources")
    if not isinstance(raw_sources, list):
        return []

    accepted = []
    for source in raw_sources:
        if not isinstance(source, dict):
            continue

        source_name = _norm(source.get("source", ""))
        if source_name not in APPROVED_EXTERNAL_SOURCES:
            continue

        samples = source.get("samples")
        if not isinstance(samples, list) or not samples:
            continue

        normalized_samples = _normalize_training_samples(samples)
        for sample in normalized_samples:
            text = sample.get("text", "")
            if _is_valid_training_text(text):
                accepted.append(sample)

    return accepted


def _dedupe_training_samples(samples):
    deduped = []
    seen = set()
    for sample in samples or []:
        text = _normalize_ws(sample.get("text", ""))
        if not text:
            continue
        key = _norm(text)
        if key in seen:
            continue
        seen.add(key)
        deduped.append(sample)
    return deduped


def _blend_training_sources(db_samples, external_samples, max_external_ratio=0.35):
    # DB samples are trusted business labels; keep them unless text is empty.
    db_clean = _dedupe_training_samples([
        s for s in (db_samples or [])
        if _normalize_ws(s.get("text", ""))
    ])
    ext_clean = _dedupe_training_samples([s for s in (external_samples or []) if _is_valid_training_text(s.get("text", ""))])

    if not db_clean:
        return ext_clean[:150]
    if not ext_clean:
        return db_clean

    max_external = int(len(db_clean) * max_external_ratio)
    max_external = max(0, min(max_external, 400))
    ext_trimmed = ext_clean[:max_external]

    # Keep DB samples first so model remains business-grounded.
    return _dedupe_training_samples(db_clean + ext_trimmed)


def _extract_training_samples(request_data, prompt):
    db_samples = []
    for k in ["trainingSamples", "training_samples", "samples", "historique"]:
        v = request_data.get(k)
        if isinstance(v, list) and v:
            db_samples = _normalize_training_samples(v)
            break
    v = _extract_json_value(prompt, "Training samples: ")
    if isinstance(v, list) and not db_samples:
        db_samples = _normalize_training_samples(v)

    external_samples = _extract_external_training_samples(request_data)
    return _blend_training_sources(db_samples, external_samples)


def _extract_autre_feedback_samples(request_data):
    raw = request_data.get("acceptedAutreFeedback")
    if not isinstance(raw, list):
        return []

    samples = []
    for item in raw:
        if not isinstance(item, dict):
            continue
        prompt = _normalize_ws(item.get("prompt", ""))
        general = item.get("general") if isinstance(item.get("general"), dict) else {}
        details = item.get("details") if isinstance(item.get("details"), dict) else {}
        field_plan = item.get("fieldPlan") if isinstance(item.get("fieldPlan"), dict) else {}
        if not prompt and not general and not details:
            continue
        samples.append({
            "prompt": prompt,
            "general": general,
            "details": details,
            "fieldPlan": field_plan,
        })

    return samples


def _feedback_similarity_score(source_text, sample):
    if not isinstance(sample, dict):
        return 0.0

    source_tokens = set(_tokenize(source_text, use_bigrams=False))
    if not source_tokens:
        return 0.0

    prompt = _normalize_ws(sample.get("prompt", ""))
    general = sample.get("general") if isinstance(sample.get("general"), dict) else {}
    details = sample.get("details") if isinstance(sample.get("details"), dict) else {}

    sample_chunks = [prompt]
    if general:
        sample_chunks.extend(str(v) for v in general.values() if isinstance(v, str) and v.strip())
    if details:
        sample_chunks.extend(str(v) for v in details.values() if isinstance(v, str) and v.strip())
    sample_text = _normalize_ws(" ".join(sample_chunks))
    sample_tokens = set(_tokenize(sample_text, use_bigrams=False))
    if not sample_tokens:
        return 0.0

    overlap = len(source_tokens & sample_tokens)
    if overlap <= 0:
        return 0.0

    jaccard = overlap / float(max(1, len(source_tokens | sample_tokens)))
    source_coverage = overlap / float(max(1, len(source_tokens)))
    return (0.65 * jaccard) + (0.35 * source_coverage)


def _feedback_timestamp(sample):
    if not isinstance(sample, dict):
        return datetime.min

    raw = _normalize_ws(sample.get("createdAt", ""))
    if not raw:
        return datetime.min

    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except Exception:
        return datetime.min


def _pick_similar_autre_feedback(source_text, feedback_samples, top_k=4, min_score=0.20):
    ranked = []
    all_samples = [s for s in (feedback_samples or []) if isinstance(s, dict)]
    source_norm = _norm(source_text)

    timestamps = [_feedback_timestamp(sample) for sample in all_samples]
    valid_timestamps = [ts for ts in timestamps if ts != datetime.min]
    ts_min = min(valid_timestamps) if valid_timestamps else datetime.min
    ts_max = max(valid_timestamps) if valid_timestamps else datetime.min

    for index, sample in enumerate(all_samples):
        score = _feedback_similarity_score(source_text, sample)

        prompt_norm = _norm(_normalize_ws(sample.get("prompt", "")))
        exact_prompt_bonus = 0.45 if prompt_norm and prompt_norm == source_norm else 0.0

        recency_bonus = 0.0
        current_ts = timestamps[index]
        if current_ts != datetime.min and ts_max != datetime.min and ts_max > ts_min:
            span = (ts_max - ts_min).total_seconds()
            if span > 0:
                recency_ratio = (current_ts - ts_min).total_seconds() / span
                recency_bonus = 0.12 * max(0.0, min(1.0, recency_ratio))

        final_score = score + exact_prompt_bonus + recency_bonus
        if final_score < min_score:
            continue
        ranked.append((final_score, current_ts, sample))

    ranked.sort(key=lambda item: (item[0], item[1]), reverse=True)
    return [(score, sample) for score, _, sample in ranked[:max(1, int(top_k))]]


def _get_feedback_detail_value(details, target_key):
    if not isinstance(details, dict) or not target_key:
        return ""

    direct = details.get(target_key)
    if isinstance(direct, (str, int, float)):
        normalized_direct = _normalize_ws(str(direct))
        if normalized_direct:
            return normalized_direct

    target_canonical = _canonical_field_key(target_key)
    for raw_key, raw_value in details.items():
        if _canonical_field_key(str(raw_key)) != target_canonical:
            continue
        if isinstance(raw_value, (str, int, float)):
            normalized_value = _normalize_ws(str(raw_value))
            if normalized_value:
                return normalized_value

    return ""


def _is_generic_detail_value(key, value, source):
    normalized_value = _norm(_normalize_ws(value))
    if not normalized_value:
        return True

    if len(normalized_value) <= 3:
        return True

    if normalized_value in {"a definir", "autre", "na", "n a", "none", "null"}:
        return True

    nk = _norm(key)
    if "description" in nk and normalized_value == _norm(source):
        return True

    return False


def _field_semantic_bucket(key):
    nk = _norm(str(key or ""))
    if any(token in nk for token in ["date"]):
        return "date"
    if any(token in nk for token in ["horaire", "heure"]):
        return "time"
    if any(token in nk for token in ["zone", "lieu", "destination", "depart", "emplacement", "localisation"]):
        return "location"
    if any(token in nk for token in ["description", "justification", "contexte", "motif", "besoin"]):
        return "long_text"
    if any(token in nk for token in ["type", "niveau", "priorite"]):
        return "label"
    return "text"


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


def _is_constraint_like_field_key(key):
    normalized = _norm(str(key or "")).replace("_", " ")
    return bool(re.search(r"\b(contrainte|condition|preference|preference)\b", normalized))


def _has_constraint_evidence(text):
    lowered = _norm(_normalize_ws(text))
    if not lowered:
        return False
    return _has_any_phrase(lowered, [_norm(marker) for marker in CONSTRAINT_MARKERS])


def _sanitize_value_for_field(key, value, source=""):
    raw_value = _normalize_ws(value)
    if not raw_value:
        return ""

    bucket = _field_semantic_bucket(key)
    normalized = _norm(raw_value)

    if bucket == "date":
        if re.match(r"^\d{4}-\d{2}-\d{2}$", raw_value):
            return raw_value
        date_value, _ = _extract_date_range(raw_value)
        return date_value or ""

    if bucket == "time":
        return _extract_arrival_time(raw_value)

    if bucket == "location":
        candidate = dyn_extract.extract_descriptive_location(source or raw_value) or dyn_extract.extract_descriptive_location(raw_value)
        if not candidate:
            candidate = _extract_parking_zone(raw_value)
        if not candidate:
            candidate = _capitalize_entity(_clean_entity_text(raw_value))
        candidate = _normalize_ws(candidate)
        if not candidate:
            return ""
        if len(candidate) > 52:
            return ""
        if len(candidate.split()) > 10:
            return ""
        if _has_any_word(_norm(candidate), ["demande", "souhaite", "veux", "besoin", "justification"]):
            return ""
        return candidate

    if bucket == "label":
        if len(raw_value.split()) > 5:
            return ""
        return raw_value

    if bucket == "long_text":
        if source and _norm(raw_value) == _norm(source):
            return raw_value
        return raw_value[:320]

    if len(normalized) <= 2:
        return ""
    if len(raw_value) > 120:
        return ""
    return raw_value


def _source_supports_field_override(source, key):
    source_text = _normalize_ws(str(source or ""))
    if not source_text:
        return False

    bucket = _field_semantic_bucket(key)
    normalized_source = _norm(source_text)

    leave_request_terms = ["conge", "congé", "arret", "arrêt", "absence", "repos"]
    expense_hotel_terms = ["hotel", "hebergement", "hébergement", "nuit", "nuitée", "nuitee"]
    expense_restaurant_terms = ["restaurant", "repas"]
    expense_transport_terms = ["taxi", "bus", "train", "avion", "vol"]

    if _canonical_field_key(key) in {"type_transport_souhaite", "ai_type_transport"}:
        return _has_any_word(normalized_source, ["taxi", "train", "bus", "autocar", "voiture", "vehicule", "navette", "avion", "vol"])

    if _canonical_field_key(key) in {"type_acces", "ai_type_acces"}:
        return _has_any_phrase(normalized_source, ["lecture seule", "lecture ecriture", "administrateur", "admin", "ecriture", "consultation"])

    if _canonical_field_key(key) in {"type_stationnement", "ai_type_stationnement"}:
        return _has_any_phrase(normalized_source, ["place reservee", "place reserve", "acces parking", "badge parking", "temporaire", "autorisation temporaire"])

    if _canonical_field_key(key) in {"type_conge", "ai_type_conge"}:
        return _has_any_phrase(normalized_source, leave_request_terms)

    if _canonical_field_key(key) in {"date_debut_conge", "ai_date_debut_conge", "date_fin_conge", "ai_date_fin_conge"}:
        return _has_any_phrase(normalized_source, leave_request_terms)

    if _canonical_field_key(key) in {"type_depense", "ai_type_depense"}:
        return (
            _has_any_phrase(normalized_source, expense_hotel_terms)
            or _has_any_phrase(normalized_source, expense_restaurant_terms)
            or _has_any_phrase(normalized_source, expense_transport_terms)
        )

    if _is_constraint_like_field_key(key):
        return _has_constraint_evidence(source_text)

    if bucket == "date":
        date_start, date_end = _extract_date_range(source_text)
        if date_start or date_end:
            return True
        return bool(re.search(r"\b(?:le|des|d[èe]s|a\s+partir\s+de)\s+\d{1,2}\b", normalized_source))

    if bucket == "time":
        return "" != _extract_arrival_time(source_text)

    if bucket == "location":
        if _extract_parking_zone(source_text):
            return True
        return bool(re.search(r"\b(?:de|depuis)\s+.+\s+(?:vers|a|à)\s+.+", normalized_source))

    return True


def _autre_can_apply_predicted_value(field_key, value, source):
    key = _normalize_ws(field_key)
    candidate = _normalize_ws(value)
    if not key or not candidate:
        return False

    if key == "ai_type_transport":
        return _source_supports_field_override(source, "type_transport_souhaite")

    if key == "ai_type_acces":
        return _source_supports_field_override(source, "type_acces")

    if key == "ai_type_stationnement":
        return _source_supports_field_override(source, "type_stationnement")

    if key == "ai_zone_souhaitee":
        prompt_zone = _normalize_ws(dyn_extract.extract_descriptive_location(source, "parking") or _extract_parking_zone(source))
        if not prompt_zone:
            return False
        return _normalize_field_compare_value(prompt_zone) == _normalize_field_compare_value(candidate)

    if key == "ai_type_conge":
        return _source_supports_field_override(source, "type_conge")

    if key in {"ai_date_debut_conge", "ai_date_fin_conge"}:
        return _source_supports_field_override(source, key)

    if key == "ai_type_depense":
        return _source_supports_field_override(source, "type_depense")

    if _is_constraint_like_field_key(key):
        return _source_supports_field_override(source, key) and _has_phrase(_norm(source), _norm(candidate))

    return True


def _feedback_detail_overrides(source, allowed_keys, ranked_feedback, regenerate_count=0):
    overrides = {}
    if not allowed_keys or not ranked_feedback:
        return overrides

    for raw_key in allowed_keys:
        key = str(raw_key)
        if _field_semantic_bucket(key) == "long_text":
            continue
        if not _source_supports_field_override(source, key):
            continue
        weighted_values = {}
        for score, sample in ranked_feedback:
            details = sample.get("details") if isinstance(sample.get("details"), dict) else {}
            candidate = _get_feedback_detail_value(details, key)
            if not candidate:
                continue
            candidate = _sanitize_value_for_field(key, candidate, source)
            if not candidate:
                continue
            weighted_values[candidate] = weighted_values.get(candidate, 0.0) + float(score)

        if not weighted_values:
            continue

        ranked_values = sorted(weighted_values.items(), key=lambda item: item[1], reverse=True)
        pick_index = min(max(0, int(regenerate_count or 0)), len(ranked_values) - 1)
        best_value, best_weight = ranked_values[pick_index]
        if best_weight >= 0.26:
            overrides[key] = best_value

    return overrides


def _feedback_context_overrides(ranked_feedback, source=""):
    context = {}
    if not ranked_feedback:
        return context

    for score, sample in ranked_feedback:
        if score < 0.40:
            continue
        details = sample.get("details") if isinstance(sample.get("details"), dict) else {}
        if not details:
            continue

        for raw_key, raw_value in details.items():
            if not isinstance(raw_value, (str, int, float)):
                continue
            value = _normalize_ws(str(raw_value))
            if not value:
                continue

            nkey = _norm(str(raw_key))
            if "type_transport" in nkey and "type_transport_souhaite" not in context:
                candidate = _sanitize_value_for_field("type_transport_souhaite", value)
                if candidate and _source_supports_field_override(source, "type_transport_souhaite"):
                    context["type_transport_souhaite"] = candidate
            elif ("lieu_depart" in nkey or "depart" == nkey) and "lieu_depart_actuel" not in context:
                candidate = _sanitize_value_for_field("lieu_depart_actuel", value)
                if candidate and _source_supports_field_override(source, "lieu_depart_actuel"):
                    context["lieu_depart_actuel"] = candidate
            elif ("lieu_souhaite" in nkey or "destination" in nkey or "zone" in nkey or "emplacement" in nkey) and "lieu_souhaite" not in context:
                candidate = _sanitize_value_for_field("lieu_souhaite", value)
                if candidate and _source_supports_field_override(source, "lieu_souhaite"):
                    context["lieu_souhaite"] = candidate
            elif ("horaire" in nkey or "heure" in nkey) and "horaire_arrivee_parking" not in context:
                candidate = _sanitize_value_for_field("horaire_arrivee_parking", value)
                if candidate and _source_supports_field_override(source, "horaire_arrivee_parking"):
                    context["horaire_arrivee_parking"] = candidate
            elif "type_formation" in nkey and "type_formation" not in context:
                candidate = _sanitize_value_for_field("type_formation", value)
                if candidate:
                    context["type_formation"] = candidate
            elif "nom_formation" in nkey and "nom_formation" not in context:
                candidate = _sanitize_value_for_field("nom_formation", value)
                if candidate:
                    context["nom_formation"] = candidate
            elif "date" in nkey and "date_souhaitee" not in context and re.match(r"^\d{4}-\d{2}-\d{2}$", value):
                candidate = _sanitize_value_for_field("date_souhaitee", value)
                if candidate and _source_supports_field_override(source, "date_souhaitee"):
                    context["date_souhaitee"] = candidate

    return context


def _feedback_custom_field_overrides(ranked_feedback, source="", regenerate_count=0):
    overrides = {}
    if not ranked_feedback:
        return overrides

    weighted = {}
    for score, sample in ranked_feedback:
        if score < 0.20:
            continue

        details = sample.get("details") if isinstance(sample.get("details"), dict) else {}
        for raw_key, raw_value in details.items():
            key = _normalize_ws(str(raw_key))
            if not key.startswith("ai_"):
                continue
            if _field_semantic_bucket(key) == "long_text":
                continue
            if not _source_supports_field_override(source, key):
                continue
            if not isinstance(raw_value, (str, int, float)):
                continue
            value = _normalize_ws(str(raw_value))
            if not value:
                continue
            value = _sanitize_value_for_field(key, value)
            if not value:
                continue
            weighted.setdefault(key, {})
            weighted[key][value] = weighted[key].get(value, 0.0) + float(score)

        field_plan = sample.get("fieldPlan") if isinstance(sample.get("fieldPlan"), dict) else {}
        add_fields = field_plan.get("add") if isinstance(field_plan.get("add"), list) else []
        for field in add_fields:
            if not isinstance(field, dict):
                continue
            key = _normalize_ws(str(field.get("key", "")))
            if not key.startswith("ai_"):
                continue
            if _field_semantic_bucket(key) == "long_text":
                continue
            if not _source_supports_field_override(source, key):
                continue
            value = _normalize_ws(str(field.get("value", "")))
            if not value:
                continue
            value = _sanitize_value_for_field(key, value)
            if not value:
                continue
            weighted.setdefault(key, {})
            weighted[key][value] = weighted[key].get(value, 0.0) + float(score)

    for key, value_weights in weighted.items():
        if not value_weights:
            continue
        ranked_values = sorted(value_weights.items(), key=lambda item: item[1], reverse=True)
        pick_index = min(max(0, int(regenerate_count or 0)), len(ranked_values) - 1)
        best_value, best_weight = ranked_values[pick_index]
        if best_weight >= 0.22:
            overrides[key] = best_value

    return overrides


def _normalize_training_samples(raw):
    normalized = []
    for s in raw:
        if not isinstance(s, dict):
            continue
        text = ""
        for k in [
            "text", "texte", "texte_brut", "texte_corrige", "input",
            "message", "description", "correctedText", "texte_corrigé",
        ]:
            v = s.get(k, "")
            if isinstance(v, str) and v.strip():
                text = v.strip()
                break
        if not text:
            continue
        cat = ""
        for k in ["categorie", "category", "cat", "type", "domaine"]:
            v = s.get(k, "")
            if isinstance(v, str) and v.strip():
                cat = v.strip()
                break
        td = ""
        for k in ["typeDemande", "type_demande", "sous_type", "subtype"]:
            v = s.get(k, "")
            if isinstance(v, str) and v.strip():
                td = v.strip()
                break
        pri = ""
        for k in ["priorite", "priority", "priorité"]:
            v = s.get(k, "")
            if isinstance(v, str) and v.strip():
                pri = v.strip().upper()
                break
        status = ""
        for k in ["status", "statut", "etat"]:
            v = s.get(k, "")
            if isinstance(v, str) and v.strip():
                status = v.strip()
                break
        normalized.append({
            "text": text, "categorie": cat, "typeDemande": td,
            "priorite": pri, "status": status,
        })
    return normalized


def _normalize_priority_label(value):
    n = _norm(value)
    if n in ("haute", "high", "urgent", "urgente"):
        return "HAUTE"
    if n in ("basse", "low", "faible"):
        return "BASSE"
    if n in ("normale", "normal", "moyenne", "medium"):
        return "NORMALE"
    return str(value or "").upper().strip()


def _align_label_to_allowed(value, allowed):
    if not allowed:
        return ""
    nv = _norm(value)
    if not nv:
        return ""

    by_norm = {_norm(a): str(a) for a in allowed}
    if nv in by_norm:
        return by_norm[nv]

    value_tokens = set(t for t in re.split(r"[^a-z0-9]+", nv) if t)
    if not value_tokens:
        return ""

    best = ""
    best_score = 0.0
    for a in allowed:
        na = _norm(a)
        at = set(t for t in re.split(r"[^a-z0-9]+", na) if t)
        if not at:
            continue
        overlap = len(value_tokens & at)
        if overlap <= 0:
            continue
        score = overlap / float(max(1, len(value_tokens | at)))
        if score > best_score:
            best_score = score
            best = str(a)
    return best if best_score >= 0.25 else ""


def _augment_training_samples(training_samples, min_target=260):
    samples = list(training_samples or [])
    if len(samples) >= min_target:
        return samples

    seed_pool = _synthetic_seed_pool()
    rnd = random.Random(178181)
    i = 0
    priority_map = {"LOW": "BASSE", "MEDIUM": "NORMALE", "HIGH": "HAUTE", "CRITICAL": "HAUTE"}

    while len(samples) < min_target:
        seed = seed_pool[i % len(seed_pool)]
        noisy = _noisify_text(seed["base"], rnd)
        corr = _auto_correct_text(noisy)
        samples.append({
            "text": corr,
            "categorie": seed.get("type", "").strip(),
            "typeDemande": seed.get("sous_type", "").strip(),
            "priorite": priority_map.get(str(seed.get("priorite", "MEDIUM")).upper(), "NORMALE"),
            "status": "",
        })
        i += 1
    return samples


def _align_training_samples(training_samples, categories, type_map, priorities):
    categories = [str(c) for c in (categories or [])]
    priorities = [str(p).upper() for p in (priorities or ["HAUTE", "NORMALE", "BASSE"])]
    all_types = []
    for cat in categories:
        for t in type_map.get(cat, []):
            if t not in all_types:
                all_types.append(str(t))

    aligned = []
    for s in training_samples or []:
        if not isinstance(s, dict):
            continue
        text = _normalize_ws(s.get("text", ""))
        if not text:
            continue

        raw_cat = str(s.get("categorie", "") or "")
        raw_type = str(s.get("typeDemande", "") or "")
        raw_pri = _normalize_priority_label(s.get("priorite", "NORMALE"))

        cat = _align_label_to_allowed(raw_cat, categories)
        typ = _align_label_to_allowed(raw_type, all_types)

        if not cat and typ:
            for c in categories:
                if typ in type_map.get(c, []):
                    cat = c
                    break
        if cat and typ not in type_map.get(cat, []):
            typ = _align_label_to_allowed(raw_type, type_map.get(cat, []))
        if not cat:
            cat = categories[0] if categories else raw_cat
        if not typ:
            typ = (type_map.get(cat) or ["Autre"])[0]

        pri = raw_pri if raw_pri in priorities else _align_label_to_allowed(raw_pri, priorities)
        if not pri:
            pri = "NORMALE" if "NORMALE" in priorities else (priorities[0] if priorities else "NORMALE")

        aligned.append({"text": text, "categorie": cat, "typeDemande": typ, "priorite": pri, "status": s.get("status", "")})

    return aligned


def _map_to_general_category(sample):
    cat = _norm(sample.get("categorie", ""))
    typ = _norm(sample.get("typeDemande", ""))
    txt = _norm(sample.get("text", ""))
    combo = " ".join(x for x in [cat, typ, txt] if x)

    mapping = {
        "it": ["it", "informatique", "systeme", "logiciel", "vpn", "acces", "incident"],
        "rh": ["rh", "ressources humaines", "conge", "attestation", "paie", "formation", "demission", "mutation"],
        "logistique": ["logistique", "transport", "maintenance", "fourniture", "bureau", "espace travail"],
        "finance": ["finance", "remboursement", "avance", "salaire", "facture", "frais"],
        "securite": ["securite", "cyber", "phishing", "piratage", "incident securite"],
    }

    best = ""
    best_score = 0
    for label, kws in mapping.items():
        score = 0
        for kw in kws:
            nkw = _norm(kw)
            if re.search(r"\b" + re.escape(nkw) + r"\b", combo):
                score += 2
            elif nkw in combo:
                score += 1
        if score > best_score:
            best_score = score
            best = label
    return best if best else "autre"


def _align_training_to_general_taxonomy(training_samples):
    aligned = []
    for s in training_samples or []:
        if not isinstance(s, dict):
            continue
        text = _normalize_ws(s.get("text", ""))
        if not text:
            continue
        gen_cat = _map_to_general_category(s)
        gen_sub = _infer_general_type_and_subtype(text)[1]
        pri = _normalize_priority_label(s.get("priorite", "NORMALE"))
        if pri not in ["HAUTE", "NORMALE", "BASSE"]:
            pri = "NORMALE"
        aligned.append({
            "text": text,
            "categorie": gen_cat,
            "typeDemande": gen_sub if gen_sub else "autre",
            "priorite": pri,
            "status": s.get("status", ""),
        })
    return aligned


# ═══════════════════════════════════════════════════════════════
# RULE-BASED SCORING
# ═══════════════════════════════════════════════════════════════

def _score_keyword_hits(normalized_text, keywords):
    count = 0
    for kw in keywords:
        if not kw:
            continue
        nkw = _norm(kw)
        if _has_phrase(normalized_text, kw):
            count += 2 if " " in nkw else 1
    return count


def _score_type_rule(type_label, normalized_text):
    nt = _norm(type_label)
    score = 0
    if _has_phrase(normalized_text, type_label):
        score += 6
    for tok in nt.split():
        if len(tok) >= 3 and _has_word(normalized_text, tok):
            score += 1
    for c, kws in TYPE_KEYWORDS.items():
        if c in nt:
            score += _score_keyword_hits(normalized_text, kws) * 2
    return score


def _score_all_types(raw_text, categories, type_map):
    nt = _norm(raw_text)
    scores = {}
    for cat in categories:
        for t in type_map.get(cat, []):
            scores[(cat, t)] = _score_type_rule(t, nt)
    return scores


def _normalize_score_map(m):
    if not m:
        return {}
    mx = max(m.values()) if m else 0
    if mx <= 0:
        return {k: 0.0 for k in m}
    return {k: float(v) / float(mx) for k, v in m.items()}


def _infer_general_type_and_subtype(corrected_text):
    normalized = _norm(corrected_text)
    best_type = "autre"
    best_subtype = "autre"
    best_score = 0

    for type_name, subtypes in GENERAL_TYPE_TAXONOMY.items():
        for subtype, keywords in subtypes.items():
            score = _score_keyword_hits(normalized, keywords)
            for word in _norm(subtype).split():
                if len(word) >= 4 and re.search(r"\b" + re.escape(word) + r"\b", normalized):
                    score += 2
            if score > best_score:
                best_type = type_name
                best_subtype = subtype
                best_score = score
    return best_type, best_subtype


# ═══════════════════════════════════════════════════════════════
# INTENT DETECTION
# ═══════════════════════════════════════════════════════════════

INTENT_SIGNALS = {
    "parking": ["parking", "stationnement", "place reservee", "place reserve", "acces parking", "badge parking"],
    "transport": ["transport", "vehicule", "navette", "deplacement", "trajet", "voyage", "taxi", "bus", "train", "vol", "billet"],
    "formation": ["formation", "stage", "seminaire", "conference", "certification", "examen", "atelier"],
    "conge": ["conge", "vacance", "absence", "repos", "maladie", "arret"],
    "acces": ["acces", "compte", "permission", "vpn", "login", "droit", "habilitation"],
    "remboursement": ["remboursement", "frais", "facture", "depense", "note de frais"],
    "materiel": ["ordinateur", "pc", "laptop", "ecran", "clavier", "souris", "imprimante"],
    "incident": ["bug", "panne", "erreur", "bloque", "connexion", "indisponible"],
    "attestation": ["attestation", "certificat", "justificatif"],
    "avance": ["avance", "acompte"],
    "mutation": ["mutation", "transfert", "affectation"],
    "teletravail": ["teletravail", "remote", "domicile"],
    "maintenance": ["maintenance", "reparation", "climatisation"],
    "securite": ["securite", "piratage", "phishing", "cyberattaque", "virus"],
}


def _detect_intents(corrected_text):
    """
    Returns list of (intent, score) tuples sorted by relevance.
    A single prompt can have multiple intents (e.g. transport + formation).
    """
    normalized = _norm(corrected_text)
    results = []
    for intent, keywords in INTENT_SIGNALS.items():
        score = _score_keyword_hits(normalized, keywords)
        if score > 0:
            results.append((intent, score))
    return sorted(results, key=lambda x: -x[1])


def _has_transport_context(corrected_text, intent_names=None, context_fields=None):
    normalized = _norm(corrected_text)
    context_fields = context_fields or {}
    if _detect_route_pattern(corrected_text):
        return True
    if context_fields.get("lieu_depart_actuel") and context_fields.get("lieu_souhaite"):
        return True
    if _has_any_word(normalized, ["transport", "vehicule", "voiture", "navette", "taxi", "bus", "train", "avion", "vol", "billet", "mission"]):
        return True
    if intent_names and "transport" in intent_names and _has_any_word(normalized, ["aller", "retour", "trajet", "vers", "depuis"]):
        return True
    return False


def _detect_route_pattern(corrected_text):
    """Detects if text contains a travel route pattern (de X vers Y)."""
    return bool(re.search(
        r"\b(?:de|depuis)\s+[a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?\s+vers\s+[a-zà-ÿ]{2,}",
        _norm(corrected_text),
    ))


def _detect_semantic_roles(text):
    normalized = _norm(text)

    finance_indicators = ["remboursement", "frais", "facture", "avance", "note"]
    formation_indicators = ["formation", "certification", "atelier"]
    parking_indicators = ["parking", "stationnement", "place"]
    workspace_indicators = ["bureau", "salle", "espace"]
    transport_indicators = ["transport", "deplacement", "trajet", "aller", "navette", "mission", "train", "bus", "taxi", "voiture", "vehicule", "avion", "vol"]

    has_route = bool(
        re.search(
            r"\b(?:de|depuis)\s+[a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?\s+vers\s+[a-zà-ÿ]{2,}",
            normalized,
        )
    )

    return {
        "has_route": has_route,
        "has_finance": _has_any_word(normalized, finance_indicators),
        "has_formation": _has_any_word(normalized, formation_indicators),
        "has_parking": _has_any_word(normalized, parking_indicators),
        "has_workspace": _has_any_word(normalized, workspace_indicators),
        "has_transport_context": _has_any_word(normalized, transport_indicators),
    }


# ═══════════════════════════════════════════════════════════════
# CONTEXT-AWARE FIELD EXTRACTION
# ═══════════════════════════════════════════════════════════════

def _build_context_fields(corrected_text, intents):
    """
    Extracts fields based on detected intents.
    Does NOT hardcode values — extracts dynamically from text.
    """
    fields = {}
    normalized = _norm(corrected_text)
    roles = _detect_semantic_roles(corrected_text)
    strong_transport = bool(roles["has_transport_context"] or roles["has_route"])

    # Always extract dates if present
    date_debut, date_fin = _extract_date_range(corrected_text)
    if date_debut:
        fields["date_souhaitee"] = date_debut
    if date_fin:
        fields["date_fin"] = date_fin

    # Days
    nombre_jours = _extract_nombre_jours(corrected_text, date_debut, date_fin)
    if nombre_jours:
        fields["nombre_jours"] = nombre_jours

    # Amount
    amount = _extract_amount(corrected_text)
    if amount:
        fields["montant"] = int(amount) if re.fullmatch(r"\d+", amount) else float(amount)

    beneficiary = _extract_explicit_beneficiary(corrected_text)
    if beneficiary:
        fields["beneficiaire"] = beneficiary

    # Justification
    justification = _extract_justification(corrected_text)
    if justification:
        fields["justification"] = justification

    # Route extraction is gated by semantic role, not regex alone.
    if roles["has_route"]:
        dep, dest = _extract_location_pair(corrected_text)
        if dep:
            fields["lieu_depart_actuel"] = dep
        if dest:
            fields["lieu_souhaite"] = dest

    # When phrasing is free-form (no explicit route), keep destination hints.
    if strong_transport and not fields.get("lieu_souhaite"):
        destination_hint = _extract_destination_hint(corrected_text)
        if destination_hint:
            fields["lieu_souhaite"] = destination_hint

    # Mission destination extraction is finance-gated when no route exists.
    if roles["has_finance"] and not roles["has_route"]:
        mission_destination = _infer_autre_mission_location(corrected_text)
        if mission_destination:
            fields["lieu_souhaite"] = mission_destination

    # Formation extraction is gated by semantic role.
    if roles["has_formation"]:
        nom = _extract_subject_name(corrected_text, "formation")
        if not nom and _has_word(normalized, "certification"):
            nom = _extract_subject_name(corrected_text, "certification")
        nom = _clean_subject_like_name(nom)
        if nom and roles["has_route"]:
            nom = _trim_route_suffix_from_subject(nom, [fields.get("lieu_depart_actuel", ""), fields.get("lieu_souhaite", "")])
        if nom:
            fields["nom_formation"] = nom

        # Formation type: infer from context, not hardcode
        if _has_word(normalized, "certification"):
            fields["type_formation"] = "Certification"
        elif _has_phrase(normalized, "formation interne"):
            fields["type_formation"] = "Formation interne"
        elif _has_phrase(normalized, "formation externe"):
            fields["type_formation"] = "Formation externe"
        elif strong_transport:
            # If they need transport for it, it's likely external
            fields["type_formation"] = "Formation externe"

    # Transport type remains available whenever transport semantics exist.
    if strong_transport:
        transport_types = {
            "vehicule": "Vehicule", "voiture": "Voiture",
            "navette": "Navette", "bus": "Bus",
            "taxi": "Taxi", "train": "Train",
            "avion": "Avion", "vol": "Avion",
        }
        detected_transport = None
        for kw, label in transport_types.items():
            if _has_word(normalized, kw):
                detected_transport = label
                break
        fields["type_transport_souhaite"] = detected_transport or "A definir"

    if roles["has_parking"]:
        zone = dyn_extract.extract_descriptive_location(corrected_text, "parking") or _extract_parking_zone(corrected_text)
        if zone:
            fields["zone_souhaitee_parking"] = zone

        horaire = _extract_arrival_time(corrected_text)
        if horaire:
            fields["horaire_arrivee_parking"] = horaire

    if roles["has_workspace"]:
        localisation = dyn_extract.extract_descriptive_location(corrected_text, "espace")
        if localisation:
            fields["localisation_souhaitee"] = localisation

    # Build description from intents
    desc_parts = ["Demande"]
    if strong_transport:
        desc_parts.append("de transport")
    if roles["has_formation"]:
        nom = fields.get("nom_formation")
        if nom:
            desc_parts.append(f"pour une formation {nom}")
        else:
            desc_parts.append("pour une formation")
    dep = fields.get("lieu_depart_actuel")
    dest = fields.get("lieu_souhaite")
    if dep and dest:
        desc_parts.append(f"de {dep} vers {dest}")
    elif dest and roles["has_finance"] and not roles["has_route"]:
        desc_parts.append(f"pour mission a {dest}")
    date = fields.get("date_souhaitee")
    if date:
        desc_parts.append(f"le {date}")

    if len(desc_parts) > 1:
        fields["description_detaillee_besoin"] = " ".join(desc_parts) + "."
    else:
        fields["description_detaillee_besoin"] = corrected_text

    # Keywords
    fields["mots_cles"] = _extract_keywords(corrected_text)

    return _dedupe_fields(fields)


def _infer_autre_mission_location(text):
    source = _normalize_ws(text)
    if not source:
        return ""

    lowered = _norm(source)
    match = re.search(
        r"\bmission\s+(?:a|à|vers|chez)\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\b",
        lowered,
        flags=re.IGNORECASE,
    )
    if match:
        candidate = _clean_entity_text(match.group(1))
        tokens = candidate.split()
        if (
            candidate
            and _norm(candidate) not in NON_LOCATION_NOISE_WORDS
            and tokens
            and all(_is_likely_proper_noun(token) for token in tokens)
        ):
            return _capitalize_entity(candidate)

    return ""


def _extract_structured_date_range(text):
    source = _normalize_ws(text)
    if not source:
        return "", ""

    compact_range = re.search(
        rf"\bdu\s+(\d{{1,2}})\s+au\s+(\d{{1,2}})\s+{MONTH_PATTERN}\s+(\d{{4}})\b",
        _norm(source),
        flags=re.IGNORECASE,
    )
    if compact_range:
        month_num = MONTH_ALIASES.get(_normalize_month_token(compact_range.group(3)))
        year = compact_range.group(4)
        if month_num:
            start = _parse_date_candidate(compact_range.group(1), month_num, year)
            end = _parse_date_candidate(compact_range.group(2), month_num, year)
            if start:
                return start, end or ""

    start, end = _extract_date_range(source)
    return start or "", end or ""


def _build_autre_structured_context(source, rule_fields, intents):
    context_fields = _build_context_fields(source, intents)

    date_debut, date_fin = _extract_structured_date_range(source)
    if date_debut:
        context_fields["date_debut"] = date_debut
        context_fields["date_souhaitee"] = date_debut
    if date_fin:
        context_fields["date_fin"] = date_fin

    transport_type = _normalize_ws(
        rule_fields.get("ai_type_transport")
        or context_fields.get("type_transport")
        or context_fields.get("type_transport_souhaite")
    )
    if transport_type:
        context_fields["type_transport"] = transport_type

    if not context_fields.get("lieu_souhaite"):
        mission_location = _infer_autre_mission_location(source)
        if mission_location:
            context_fields["lieu_souhaite"] = mission_location

    context_fields["hebergement"] = bool(re.search(r"\b(?:hebergement|hotel)\b", _norm(source)))

    if re.search(r"\b(?:remboursement|rembourse|note de frais|frais)\b", _norm(source)):
        context_fields["type_demande"] = "remboursement"

    return context_fields


def _extract_constraint_clause_for_justification(text):
    source = _normalize_ws(text)
    if not source:
        return ""

    patterns = [
        (r"\b(sans\s+[a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2})\b", None),
        (r"\b(pas\s+de\s+[a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2})\b", None),
        (r"\b(?:uniquement|seulement|exclusivement)\s+(?:en\s+)?([a-zà-ÿ][a-zà-ÿ'\-]*(?:\s+[a-zà-ÿ][a-zà-ÿ'\-]*){0,2})\b", "uniquement en"),
    ]

    for pattern, prefix in patterns:
        match = re.search(pattern, source, flags=re.IGNORECASE)
        if not match:
            continue
        captured = _normalize_ws(match.group(1))
        if not captured:
            continue
        if prefix:
            captured = _normalize_ws(captured)
            if captured.lower().startswith("en "):
                return captured.lower().replace("en ", "uniquement en ", 1)
            return f"{prefix} {captured.lower()}"
        return captured.lower()

    return ""


def _build_structured_justification(context_fields, corrected_text):
    parts = []
    normalized_text = _norm(corrected_text)
    has_mission = bool(re.search(r"\bmission\b", normalized_text))

    if _norm(context_fields.get("type_demande", "")) == "remboursement":
        parts.append("Remboursement de frais")

    transport_type = _normalize_ws(context_fields.get("type_transport") or context_fields.get("type_transport_souhaite"))
    valid_transport = transport_type and _norm(transport_type) != "a definir"
    depart = _normalize_ws(context_fields.get("lieu_depart_actuel"))
    destination = _normalize_ws(context_fields.get("lieu_souhaite"))

    if depart and destination:
        if valid_transport:
            parts.append(f"Demande de transport en {transport_type.lower()} de {depart} vers {destination}")
        else:
            parts.append(f"Demande de transport de {depart} vers {destination}")
    elif valid_transport:
        parts.append(f"en {transport_type.lower()}")

    if context_fields.get("hebergement"):
        parts.append("et hebergement")

    if _has_phrase(normalized_text, "mission client"):
        parts.append("mission client")
    elif has_mission and destination:
        parts.append(f"pour mission a {context_fields['lieu_souhaite']}")

    constraint_clause = _extract_constraint_clause_for_justification(corrected_text)
    if constraint_clause:
        parts.append(f"Contrainte : {constraint_clause}")

    date_debut = _normalize_ws(context_fields.get("date_debut") or context_fields.get("date_souhaitee"))
    date_fin = _normalize_ws(context_fields.get("date_fin"))
    if date_debut and date_fin:
        parts.append(f"du {date_debut} au {date_fin}")
    elif date_debut:
        parts.append(f"le {date_debut}")

    justification = _normalize_ws(" ".join(parts))
    if justification:
        return justification + "."
    return _normalize_ws(corrected_text)


def _structured_justification_preserves_evidence(prompt, structured_description):
    prompt_norm = _norm(prompt)
    structured_norm = _norm(structured_description)
    sensitive_terms = [
        "maladie", "medical", "hospitalisation",
        "hotel", "hebergement", "restaurant", "repas",
        "taxi", "bus", "train", "avion", "vol",
    ]
    for term in sensitive_terms:
        if _has_word(prompt_norm, term) and not _has_word(structured_norm, term):
            return False
    return True


# ═══════════════════════════════════════════════════════════════
# ML ORCHESTRATOR
# ═══════════════════════════════════════════════════════════════

def _train_all_models(training_samples):
    if not isinstance(training_samples, list) or len(training_samples) < 20:
        return None
    cat_nb = _train_tfidf_nb(training_samples, "categorie")
    type_nb = _train_tfidf_nb(training_samples, "typeDemande")
    pri_nb = _train_tfidf_nb(training_samples, "priorite")
    models = {
        "category_nb": cat_nb, "type_nb": type_nb, "priority_nb": pri_nb,
        "category_perceptron": None, "type_perceptron": None, "priority_perceptron": None,
    }
    for task, nb, lk in [
        ("category", cat_nb, "categorie"),
        ("type", type_nb, "typeDemande"),
        ("priority", pri_nb, "priorite"),
    ]:
        if nb and len(nb["labels"]) >= 2:
            p = AveragedPerceptron()
            p.train(training_samples, nb, lk, epochs=8)
            models[f"{task}_perceptron"] = p
    return models


def _predict_with_ensemble(models, text, allowed, task="category"):
    nb_key = f"{task}_nb"
    pk = f"{task}_perceptron"
    nb = models.get(nb_key) if models else None
    perc = models.get(pk) if models else None
    _, _, nb_probs = _predict_tfidf_nb(nb, text)
    if perc and perc.labels:
        _, perc_probs = perc.predict(text, nb_probs)
    else:
        perc_probs = nb_probs
    allowed_set = set(str(l) for l in allowed)

    def filt(probs):
        f = {k: v for k, v in probs.items() if k in allowed_set}
        if not f:
            return probs
        t = sum(f.values())
        return {k: v / t for k, v in f.items()} if t > 0 else f

    nbf = filt(nb_probs)
    pf = filt(perc_probs)
    combined = {l: 0.55 * nbf.get(l, 0.0) + 0.30 * pf.get(l, 0.0) for l in allowed_set}
    if not combined:
        return "", 0.0
    best = max(combined, key=combined.get)
    t = sum(combined.values())
    return best, round(combined[best] / t, 4) if t > 0 else 0.0


# ═══════════════════════════════════════════════════════════════
# SYNTHETIC DATA
# ═══════════════════════════════════════════════════════════════

def _synthetic_seed_pool():
    return [
        {"base": "Bonjour je souhaite un conge maladie du 12/05/2026 au 14/05/2026 pour raison medicale.", "type": "rh", "sous_type": "conge maladie", "priorite": "HIGH"},
        {"base": "Je demande un arret maladie de 3 jours a partir du 2026-06-01.", "type": "rh", "sous_type": "conge maladie", "priorite": "HIGH"},
        {"base": "Merci de valider mon conge annuel du 01/08/2026 au 10/08/2026.", "type": "rh", "sous_type": "conge annuel", "priorite": "MEDIUM"},
        {"base": "Je voudrais prendre mes conges payes du 15 juillet 2026 au 30 juillet 2026.", "type": "rh", "sous_type": "conge annuel", "priorite": "MEDIUM"},
        {"base": "J ai besoin d un acces VPN et CRM des aujourd hui.", "type": "it", "sous_type": "acces systeme", "priorite": "HIGH"},
        {"base": "Pouvez-vous m ouvrir les droits sur le portail RH.", "type": "it", "sous_type": "acces systeme", "priorite": "MEDIUM"},
        {"base": "Le serveur de production est indisponible et bloque toute l equipe.", "type": "it", "sous_type": "incident technique", "priorite": "CRITICAL"},
        {"base": "Mon ordinateur plante au demarrage.", "type": "it", "sous_type": "incident technique", "priorite": "HIGH"},
        {"base": "La connexion wifi est tres lente depuis hier.", "type": "it", "sous_type": "incident technique", "priorite": "MEDIUM"},
        {"base": "J ai besoin d un nouvel ecran car le mien est casse.", "type": "it", "sous_type": "materiel informatique", "priorite": "MEDIUM"},
        {"base": "Demande d un laptop pour le travail a distance.", "type": "it", "sous_type": "materiel informatique", "priorite": "MEDIUM"},
        {"base": "J ai besoin d une licence Adobe pour mon projet.", "type": "it", "sous_type": "logiciel", "priorite": "MEDIUM"},
        {"base": "Je demande le remboursement de frais de transport montant 85 dinars.", "type": "finance", "sous_type": "remboursement frais", "priorite": "MEDIUM"},
        {"base": "Note de frais pour deplacement professionnel facture 320 DT.", "type": "finance", "sous_type": "remboursement frais", "priorite": "MEDIUM"},
        {"base": "Je sollicite une avance sur salaire de 500 dinars.", "type": "finance", "sous_type": "avance sur salaire", "priorite": "MEDIUM"},
        {"base": "Pouvez-vous me fournir une attestation de travail.", "type": "rh", "sous_type": "attestation", "priorite": "MEDIUM"},
        {"base": "J ai besoin d un certificat de travail pour renouveler mon visa.", "type": "rh", "sous_type": "attestation", "priorite": "MEDIUM"},
        {"base": "Je souhaite participer a une formation externe en securite cloud le 20/06/2026.", "type": "rh", "sous_type": "formation", "priorite": "MEDIUM"},
        {"base": "Demande de participation a une certification AWS en juillet 2026.", "type": "rh", "sous_type": "formation", "priorite": "MEDIUM"},
        {"base": "Suspicion de phishing sur mon compte avec fuite potentielle de donnees.", "type": "securite", "sous_type": "incident securite", "priorite": "CRITICAL"},
        {"base": "Demande de maintenance pour la climatisation du bureau.", "type": "logistique", "sous_type": "maintenance", "priorite": "MEDIUM"},
        {"base": "J ai besoin d une chaise ergonomique.", "type": "logistique", "sous_type": "espace travail", "priorite": "LOW"},
        {"base": "Demande de fournitures bureau stylos papier.", "type": "logistique", "sous_type": "fournitures", "priorite": "LOW"},
        {"base": "Je souhaite une mutation vers un autre bureau.", "type": "rh", "sous_type": "mutation", "priorite": "MEDIUM"},
        {"base": "Je signale 12 heures supplementaires cette semaine.", "type": "rh", "sous_type": "heures supplementaires", "priorite": "MEDIUM"},
        {"base": "Je souhaite passer en teletravail 3 jours par semaine.", "type": "it", "sous_type": "teletravail", "priorite": "MEDIUM"},
        {"base": "Demande de transport pour une formation le 10 mars 2027.", "type": "logistique", "sous_type": "transport", "priorite": "MEDIUM"},
        {"base": "Besoin d un vehicule pour deplacement professionnel.", "type": "logistique", "sous_type": "transport", "priorite": "MEDIUM"},
    ]


def _noisify_text(base, rnd):
    text = _normalize_ws(base)
    typos = {
        "demande": "dmande", "bonjour": "bjr", "conge": "conje",
        "formation": "formtion", "acces": "acess", "remboursement": "remboursment",
        "ordinateur": "ordiateur", "janvier": "janv", "fevrier": "fevr", "juillet": "juil",
    }
    for s, d in typos.items():
        if rnd.random() < 0.15:
            text = re.sub(rf"\b{s}\b", d, text, flags=re.IGNORECASE)
    if rnd.random() < 0.20:
        text = f"{rnd.choice(['bjr', 'salut', 'svp'])} {text}"
    if rnd.random() < 0.15:
        words = text.split()
        if len(words) > 4:
            idx = rnd.randint(1, len(words) - 2)
            words.insert(idx, words[idx])
            text = " ".join(words)
    return text.strip()


def _generate_dataset_samples(target_count):
    rnd = random.Random(178181)
    seeds = _synthetic_seed_pool()
    emp = [178, 181, 172, 190, 205, 181, 178, 199, 181, 178]
    samples = []
    target = max(500, int(target_count or 500))
    for i in range(target):
        seed = seeds[i % len(seeds)]
        brut = _noisify_text(seed["base"], rnd)
        corrige = _auto_correct_text(brut)
        it, ist = _infer_general_type_and_subtype(corrige)
        intents = _detect_intents(corrige)
        context = {
            "raw_text": corrige, "inferred_type": it, "inferred_subtype": ist,
        }
        date_debut, _ = _extract_date_range(corrige)
        context["days_until_deadline"] = _days_until(date_debut)
        amt = _extract_amount(corrige)
        context["amount"] = float(amt) if amt else None
        pri, _, _ = _compute_priority_via_matrix(corrige, context)
        champs = _build_context_fields(corrige, intents)

        samples.append({
            "employee_id": emp[i % len(emp)],
            "texte_brut": brut,
            "texte_corrige": corrige,
            "type_demande": it if it != "autre" else seed["type"],
            "sous_type": ist if ist != "autre" else seed["sous_type"],
            "priorite": pri or seed["priorite"],
            "champs_extraits": champs,
        })
    return samples


# ═══════════════════════════════════════════════════════════════
# RESPONSE BUILDERS
# ═══════════════════════════════════════════════════════════════

def _build_title(type_demande, corrected_text):
    cleaned = _normalize_ws(corrected_text)
    lowered = _norm(cleaned)
    for p in [
        r"^(bonjour|salut|bonsoir)[,.]?\s*",
        r"^(je souhaite|je veux|je voudrais|j ai besoin de?|merci de)\s*",
        r"^(une|un|la|le|de|d)\s+",
    ]:
        lowered = re.sub(p, "", lowered).strip()
    if lowered:
        title = lowered[:1].upper() + lowered[1:]
        return (title[:87].rstrip() + "...") if len(title) > 90 else title
    return f"Demande de {type_demande}" if type_demande else "Nouvelle demande"


def _build_autre_title(source, context_fields, intents):
    intent_names = {name for name, _ in intents}
    strong_transport = _has_transport_context(source, intent_names, context_fields)
    dep = context_fields.get("lieu_depart_actuel")
    dest = context_fields.get("lieu_souhaite")
    date_value = context_fields.get("date_souhaitee")
    formation_name = context_fields.get("nom_formation")

    if strong_transport and dep and dest:
        title = f"Transport de {dep} vers {dest}"
        if formation_name:
            title += f" pour {formation_name}"
        return title

    if formation_name:
        prefix = "Certification" if context_fields.get("type_formation") == "Certification" else "Formation"
        return f"{prefix} {formation_name}"

    simplified = _norm(source)
    simplified = re.sub(r"^(?:bonjour|salut|bonsoir)\s*", "", simplified).strip()
    simplified = re.sub(r"^(?:je souhaite|je veux|je voudrais|j ai besoin de|merci de|demande d un|demande de)\s*", "", simplified).strip()
    simplified = re.sub(r"\ble\s+\d{1,2}\s+[a-z]+\s+\d{4}\b", "", simplified).strip()
    simplified = re.sub(r"\b\d{4}-\d{2}-\d{2}\b", "", simplified).strip()
    words = [word for word in simplified.split() if len(word) >= 2][:8]
    if words:
        phrase = " ".join(words)
        return phrase[:1].upper() + phrase[1:90]

    keywords = context_fields.get("mots_cles") or []
    if isinstance(keywords, list) and keywords:
        subject = " ".join(str(word) for word in keywords[:4] if str(word).strip())
        if subject:
            title = f"Demande {subject}"
            if date_value:
                title += f" {date_value}"
            return title[:90]

    return _build_title("personnalisee", source)


def _urgency_value(priorite, options):
    if not options:
        return "Normale"
    nopts = [str(o) for o in options]
    mapping = {
        "HAUTE": ["Tres urgente", "Urgente", "Haute", "Elevee"],
        "NORMALE": ["Normale", "Moyenne", "Standard"],
        "BASSE": ["Faible", "Basse"],
    }
    for c in mapping.get(priorite, []):
        for o in nopts:
            if _norm(c) == _norm(o):
                return o
    for o in nopts:
        if _norm(o) == "normale":
            return o
    return nopts[0]


def _map_priority_to_allowed(matrix_priority, allowed):
    """Maps decision matrix output (LOW/MEDIUM/HIGH/CRITICAL) to allowed priority labels."""
    allowed_upper = [str(x).upper() for x in allowed]
    mapping = {
        "CRITICAL": ["CRITIQUE", "CRITICAL", "HAUTE", "HIGH"],
        "HIGH": ["HAUTE", "HIGH"],
        "MEDIUM": ["NORMALE", "MEDIUM", "MOYENNE"],
        "LOW": ["BASSE", "LOW", "FAIBLE"],
    }
    for candidate in mapping.get(matrix_priority, []):
        if candidate in allowed_upper:
            return candidate
    if "NORMALE" in allowed_upper:
        return "NORMALE"
    return allowed_upper[len(allowed_upper) // 2] if allowed_upper else "NORMALE"


def _detail_default_value(key, corrected, priorite, urgency_options, title):
    nk = _norm(key)
    date_val, _ = _extract_date_range(corrected)
    amount = _extract_amount(corrected)
    if "description" in nk:
        return corrected
    if "besoin" in nk or "titre" in nk:
        return title or "Demande personnalisee"
    if "urgence" in nk:
        return _urgency_value(priorite, urgency_options)
    if "date" in nk:
        return date_val or ""
    if "montant" in nk and amount:
        return amount
    if "piece" in nk or "contexte" in nk:
        return corrected[:220]
    return ""


def _append_custom_field(custom_fields, key, label, field_type, required, value="", options=None):
    sanitized_value = _sanitize_value_for_field(key, value)
    if field_type in ["textarea", "text"] and not sanitized_value and _normalize_ws(value):
        sanitized_value = _normalize_ws(value)

    field = {
        "key": key,
        "label": label,
        "type": field_type,
        "required": bool(required),
        "value": sanitized_value,
    }
    if field_type == "select":
        field["options"] = [str(opt) for opt in (options or []) if str(opt).strip()]
    custom_fields.append(field)


def _rotate_regenerate_select_value(field, regenerate_count):
    if not isinstance(field, dict):
        return
    if str(field.get("type", "")).strip().lower() != "select":
        return

    options = [str(opt).strip() for opt in (field.get("options") or []) if str(opt).strip()]
    if len(options) <= 1:
        return

    current = _normalize_ws(field.get("value", ""))
    try:
        current_index = options.index(current)
    except ValueError:
        current_index = 0

    shift = max(1, int(regenerate_count or 1))
    field["value"] = options[(current_index + shift) % len(options)]


def _apply_regenerate_variation(custom_fields, regenerate_count, context_fields=None):
    if int(regenerate_count or 0) <= 0:
        return

    context_fields = context_fields or {}
    transport_signal = _normalize_ws(context_fields.get("type_transport_souhaite", ""))
    explicit_transport = bool(transport_signal) and _norm(transport_signal) not in {"a definir", "a_definir", "indefini"}
    explicit_formation = bool(_normalize_ws(context_fields.get("type_formation", "")))

    for field in custom_fields or []:
        key = _normalize_ws(str((field or {}).get("key", "")))
        if not key:
            continue

        if key == "ai_type_transport" and not explicit_transport:
            _rotate_regenerate_select_value(field, regenerate_count)
        elif key == "ai_type_formation" and not explicit_formation:
            _rotate_regenerate_select_value(field, regenerate_count)
        elif key == "ai_type_stationnement":
            _rotate_regenerate_select_value(field, regenerate_count)


def _build_autre_custom_fields(source, details, context_fields, intents):
    custom_fields = []
    intent_names = {name for name, _ in intents}
    description_value = (
        context_fields.get("description_detaillee_besoin")
        or details.get("descriptionBesoin")
        or source
    )
    normalized = _norm(source)

    has_parking_context = (
        "parking" in intent_names
        or _has_any_phrase(normalized, ["parking", "stationnement", "place reservee", "place reserve", "acces parking", "badge parking"])
    )

    has_transport_context = _has_transport_context(source, intent_names, context_fields)
    has_formation_context = (
        not has_parking_context
        and (
        "formation" in intent_names
        or context_fields.get("nom_formation")
        or context_fields.get("type_formation")
        )
    )
    has_workspace_context = (
        not has_parking_context
        and (
            "workplace" in intent_names
            or "espace travail" in intent_names
            or _has_any_phrase(normalized, ["bureau", "espace de travail", "espace travail", "poste de travail", "fenetre", "porte", "salle de pause"])
        )
    )

    if has_parking_context:
        parking_type = "Place reservee"
        if _has_word(normalized, "temporaire"):
            parking_type = "Autorisation temporaire"
        elif _has_any_phrase(normalized, ["acces parking", "badge parking"]):
            parking_type = "Acces parking"

        zone = _normalize_ws(context_fields.get("zone_souhaitee_parking", ""))
        if not zone and has_parking_context:
            zone = _normalize_ws(dyn_extract.extract_descriptive_location(source, "parking"))
        if not zone:
            zone = _extract_parking_zone(source)
        if not zone:
            if _has_any_word(normalized, ["entree", "principale"]):
                zone = "Entree principale"
            else:
                zone = ""
        horaire_arrivee = _normalize_ws(context_fields.get("horaire_arrivee_parking", "")) or _extract_arrival_time(source)

        _append_custom_field(
            custom_fields,
            "ai_type_stationnement",
            "Type de demande parking",
            "select",
            True,
            parking_type,
            ["Place reservee", "Acces parking", "Autorisation temporaire", "Autre"],
        )
        _append_custom_field(
            custom_fields,
            "ai_zone_souhaitee",
            "Zone ou emplacement souhaite",
            "text",
            True,
            zone,
        )
        _append_custom_field(
            custom_fields,
            "ai_horaire_arrivee",
            "Horaire habituel d arrivee",
            "text",
            False,
            horaire_arrivee,
        )
        _append_custom_field(
            custom_fields,
            "ai_justification_stationnement",
            "Justification du besoin parking",
            "textarea",
            True,
            description_value,
        )

    if has_workspace_context:
        localisation = _normalize_ws(dyn_extract.extract_descriptive_location(source, "espace"))
        if localisation:
            _append_custom_field(
                custom_fields,
                "ai_localisation_souhaitee",
                "Localisation souhaitee",
                "text",
                False,
                localisation,
            )

    if has_formation_context:
        _append_custom_field(
            custom_fields,
            "ai_type_formation",
            "Type de formation",
            "select",
            True,
            context_fields.get("type_formation", "Autre"),
            ["Formation interne", "Formation externe", "Certification", "Autre"],
        )
        _append_custom_field(
            custom_fields,
            "ai_nom_formation",
            "Nom de la formation",
            "text",
            True,
            context_fields.get("nom_formation", ""),
        )

    if has_transport_context:
        _append_custom_field(
            custom_fields,
            "ai_lieu_depart_actuel",
            "Lieu de depart actuel",
            "text",
            True,
            context_fields.get("lieu_depart_actuel", ""),
        )
        _append_custom_field(
            custom_fields,
            "ai_lieu_souhaite",
            "Lieu souhaite",
            "text",
            True,
            context_fields.get("lieu_souhaite", ""),
        )
        _append_custom_field(
            custom_fields,
            "ai_type_transport",
            "Type de transport souhaite",
            "select",
            False,
            context_fields.get("type_transport_souhaite", "A definir"),
            ["A definir", "Bus", "Train", "Voiture", "Vehicule", "Taxi", "Navette"],
        )
        _append_custom_field(
            custom_fields,
            "ai_justification_transport",
            "Justification du besoin transport",
            "textarea",
            True,
            description_value,
        )

    if context_fields.get("date_souhaitee"):
        _append_custom_field(
            custom_fields,
            "ai_date_souhaitee_metier",
            "Date demandee",
            "date",
            False,
            context_fields.get("date_souhaitee", ""),
        )

    if (
        context_fields.get("justification")
        and not any(f.get("key") == "ai_justification_transport" for f in custom_fields)
    ):
        _append_custom_field(
            custom_fields,
            "ai_justification_metier",
            "Justification",
            "textarea",
            False,
            context_fields.get("justification", ""),
        )

    custom_fields = _append_generic_context_fields(custom_fields, context_fields)

    deduped = []
    seen = set()
    for field in custom_fields:
        key = str(field.get("key", "")).strip()
        if not key or key in seen:
            continue
        seen.add(key)
        deduped.append(field)
    return deduped[:8]


def _generic_custom_field_definition(key, value):
    normalized_key = _canonical_field_key(key)
    text_value = _normalize_ws(value)
    mapping = {
        "date_souhaitee": {
            "key": "ai_date_souhaitee_extra",
            "label": "Date souhaitee",
            "type": "date",
            "required": False,
        },
        "date_fin": {
            "key": "ai_date_fin_extra",
            "label": "Date de fin",
            "type": "date",
            "required": False,
        },
        "nombre_jours": {
            "key": "ai_nombre_jours",
            "label": "Nombre de jours",
            "type": "number",
            "required": False,
        },
        "montant": {
            "key": "ai_montant",
            "label": "Montant",
            "type": "number",
            "required": False,
        },
        "justification": {
            "key": "ai_justification_metier",
            "label": "Justification",
            "type": "textarea",
            "required": False,
        },
        "lieu_depart_actuel": {
            "key": "ai_lieu_depart_actuel",
            "label": "Lieu de depart actuel",
            "type": "text",
            "required": True,
        },
        "lieu_souhaite": {
            "key": "ai_lieu_souhaite",
            "label": "Lieu souhaite",
            "type": "text",
            "required": True,
        },
        "type_transport_souhaite": {
            "key": "ai_type_transport",
            "label": "Type de transport souhaite",
            "type": "select",
            "required": False,
            "options": ["A definir", "Bus", "Train", "Voiture", "Vehicule", "Taxi", "Navette"],
        },
        "type_formation": {
            "key": "ai_type_formation",
            "label": "Type de formation",
            "type": "select",
            "required": True,
            "options": ["Formation interne", "Formation externe", "Certification", "Autre"],
        },
        "nom_formation": {
            "key": "ai_nom_formation",
            "label": "Nom de la formation",
            "type": "text",
            "required": True,
        },
    }
    definition = mapping.get(normalized_key)
    if not definition or text_value == "":
        return None
    field = {
        "key": definition["key"],
        "label": definition["label"],
        "type": definition["type"],
        "required": definition["required"],
        "value": text_value,
    }
    if definition.get("type") == "select":
        field["options"] = list(definition.get("options", []))
    return field


def _append_generic_context_fields(custom_fields, context_fields):
    existing_keys = {str(field.get("key", "")).strip() for field in custom_fields if isinstance(field, dict)}
    for raw_key, raw_value in context_fields.items():
        generic = _generic_custom_field_definition(raw_key, raw_value)
        if not generic:
            continue
        key = str(generic.get("key", "")).strip()
        if not key or key in existing_keys:
            continue
        custom_fields.append(generic)
        existing_keys.add(key)
        if len(custom_fields) >= 8:
            break
    return custom_fields[:8]


def _normalize_field_compare_value(value):
    return _norm(_normalize_ws(value))


def _custom_field_value(custom_fields, *keys):
    normalized_keys = {_normalize_field_compare_value(key) for key in keys if key}
    if not normalized_keys:
        return ""
    for field in custom_fields or []:
        if not isinstance(field, dict):
            continue
        field_key = _normalize_field_compare_value(field.get("key", ""))
        if field_key in normalized_keys:
            return _normalize_ws(field.get("value", ""))
    return ""


def _is_objet_field_key(field_key):
    normalized = _normalize_field_compare_value(field_key).replace("_", " ")
    return bool(re.search(r"\bobjet\b", normalized))


def _is_too_generic_objet(value):
    token = _normalize_field_compare_value(value)
    generic = {
        "",
        "objet",
        "frais",
        "depense",
        "transport",
        "taxi",
        "train",
        "bus",
        "restaurant",
        "remboursement",
        "remboursement de frais",
    }
    return token in generic


def _value_has_prompt_support(prompt_text, value):
    prompt_norm = _normalize_field_compare_value(prompt_text)
    value_norm = _normalize_field_compare_value(value)
    if not prompt_norm or not value_norm:
        return False
    if _has_phrase(prompt_norm, value_norm):
        return True
    prompt_tokens = set(_tokenize(prompt_text, use_bigrams=False))
    value_tokens = set(_tokenize(value, use_bigrams=False))
    if not prompt_tokens or not value_tokens:
        return False
    overlap_ratio = len(prompt_tokens & value_tokens) / float(len(value_tokens))
    return overlap_ratio >= 0.6


def _limit_custom_fields_keep_explicit(custom_fields, max_fields=8):
    if len(custom_fields) <= max_fields:
        return custom_fields

    trimmed = list(custom_fields)

    def _remove_from_end(predicate):
        nonlocal trimmed
        index = len(trimmed) - 1
        while len(trimmed) > max_fields and index >= 0:
            field = trimmed[index]
            if isinstance(field, dict) and predicate(field):
                trimmed.pop(index)
            index -= 1

    # First remove inferred/default fields while preserving relative order.
    _remove_from_end(lambda field: _normalize_field_compare_value(field.get("source", "")) in {"inferred", "default"})
    # Then remove non-explicit optional fields if still above the limit.
    _remove_from_end(
        lambda field: (
            _normalize_field_compare_value(field.get("source", "")) != "explicit"
            and not bool(field.get("required"))
        )
    )

    return trimmed[:max_fields]


def _dedupe_and_refine_custom_fields(custom_fields, context_fields, corrected_text):
    if not isinstance(custom_fields, list):
        return []

    fields = [field for field in custom_fields if isinstance(field, dict)]
    context = context_fields if isinstance(context_fields, dict) else {}
    normalized_text = _normalize_field_compare_value(corrected_text)

    type_transport = _normalize_ws(
        _custom_field_value(fields, "ai_type_transport", "type_transport_souhaite")
        or context.get("type_transport")
        or context.get("type_transport_souhaite")
    )
    type_depense = _normalize_ws(
        _custom_field_value(fields, "ai_type_depense", "type_depense")
        or context.get("type_depense")
        or context.get("ai_type_depense")
    )
    nom_formation = _normalize_ws(
        _custom_field_value(fields, "ai_nom_formation", "nom_formation")
        or context.get("nom_formation")
    )
    lieu_souhaite = _normalize_ws(
        _custom_field_value(fields, "ai_lieu_souhaite", "lieu_souhaite")
        or context.get("lieu_souhaite")
    )

    dedupe_targets = {
        _normalize_field_compare_value(type_transport),
        _normalize_field_compare_value(type_depense),
        _normalize_field_compare_value(nom_formation),
        _normalize_field_compare_value(lieu_souhaite),
    }
    dedupe_targets.discard("")

    justification = _normalize_ws(
        _custom_field_value(fields, "ai_justification_metier", "justification")
        or context.get("justification")
    )
    mission_client = (
        "mission client" in normalized_text
        or "mission client" in _normalize_field_compare_value(justification)
    )
    explicit_beneficiary_evidence = bool(_extract_explicit_beneficiary(corrected_text))
    constraint_evidence = _has_constraint_evidence(corrected_text)
    finance_context = bool(
        _normalize_field_compare_value(context.get("type_demande", "")) == "remboursement"
        or any(
            marker in normalized_text
            for marker in ["remboursement", "frais", "note de frais", "depense", "avance"]
        )
    )

    filtered = []
    has_objet_field = False
    for field in fields:
        key = _normalize_ws(field.get("key", ""))
        normalized_key = _normalize_field_compare_value(key).replace("_", " ")
        if _is_constraint_like_field_key(key) and not constraint_evidence:
            continue
        if "beneficiaire" in normalized_key and not explicit_beneficiary_evidence:
            continue
        if key.startswith("ai_custom_") and _normalize_ws(field.get("type", "")) == "select" and _normalize_ws(field.get("source", "")) != "explicit":
            field["value"] = ""
        if key.startswith("ai_custom_") and _normalize_ws(field.get("source", "")) != "explicit":
            candidate_value = _normalize_ws(field.get("value", ""))
            if candidate_value and not _value_has_prompt_support(corrected_text, candidate_value):
                continue
        if not _is_objet_field_key(key):
            filtered.append(field)
            continue

        has_objet_field = True

        current_value = _normalize_ws(field.get("value", ""))
        current_norm = _normalize_field_compare_value(current_value)
        is_redundant = current_norm in dedupe_targets
        finance_objet_needs_refine = bool(
            finance_context
            and (
                not current_norm
                or _is_too_generic_objet(current_value)
                or not any(marker in current_norm for marker in ["frais", "remboursement", "mission"])
            )
        )

        refined_value = current_value
        if is_redundant or finance_objet_needs_refine:
            if finance_context:
                if type_transport:
                    refined_value = f"Frais de {type_transport.lower()}"
                elif type_depense:
                    refined_value = f"Frais de {type_depense.lower()}"
                else:
                    refined_value = "Remboursement de frais"
            if mission_client:
                refined_value = _normalize_ws(refined_value)
                refined_value = f"{refined_value} - mission client" if refined_value else "Mission client"

        if not _normalize_ws(refined_value) or _is_too_generic_objet(refined_value):
            continue

        field["value"] = _sentence_case(refined_value)
        filtered.append(field)

    if not has_objet_field and finance_context:
        synthesized_objet = ""
        if type_transport:
            synthesized_objet = f"Frais de {type_transport.lower()}"
        elif type_depense:
            synthesized_objet = f"Frais de {type_depense.lower()}"
        else:
            synthesized_objet = "Remboursement de frais"
        if mission_client:
            synthesized_objet = f"{_normalize_ws(synthesized_objet)} - mission client"
        if _normalize_ws(synthesized_objet) and not _is_too_generic_objet(synthesized_objet):
            filtered.append(
                {
                    "key": "ai_custom_objet",
                    "label": "Objet",
                    "type": "text",
                    "required": False,
                    "value": _sentence_case(synthesized_objet),
                    "source": "inferred",
                }
            )

    return _limit_custom_fields_keep_explicit(filtered, max_fields=8)


def _extract_location_pair(corrected_text):
    """
    Dynamic location extraction with stop markers so route destinations
    do not absorb trailing intent phrases like "pour une formation ...".
    """
    lowered = _norm(corrected_text)
    patterns = [
        r"\bde\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)(?=\s+(?:pour|afin|car|avec|le|la|une|un)\b|$)",
        r"\bdepuis\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)(?=\s+(?:pour|afin|car|avec|le|la|une|un)\b|$)",
        r"\bdepart\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b.*?\b(?:destination|arrivee)\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        raw_dep = _normalize_ws(match.group(1))
        raw_dest = _normalize_ws(match.group(2))
        dep_valid = any(_is_likely_proper_noun(w) for w in raw_dep.split())
        dest_valid = any(_is_likely_proper_noun(w) for w in raw_dest.split())
        if dep_valid and dest_valid and _norm(raw_dep) != _norm(raw_dest):
            return _capitalize_entity(raw_dep), _capitalize_entity(raw_dest)
    return None, None


def _extract_subject_name(corrected_text, subject_keyword):
    """
    Extract subject names after phrases like "formation java", including
    short tech labels and symbols such as C++, C#, UI/UX, Node.js.
    """
    lowered = _norm(corrected_text)
    patterns = [
        rf"\b{re.escape(subject_keyword)}\s+(?:en\s+|sur\s+|de\s+)?([a-z][a-z0-9\s\-\./+#]*[a-z0-9+#])(?=\s+le\b|\s+du\b|\s+de\b|\s+vers\b|\s+pour\b|\s+a\b|$)",
        rf"\bpour\s+(?:une?\s+)?{re.escape(subject_keyword)}\s+(?:en\s+|sur\s+|de\s+)?([a-z][a-z0-9\s\-\./+#]*[a-z0-9+#])(?=\s+le\b|\s+du\b|\s+de\b|\s+vers\b|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        raw = match.group(1).strip()
        cleaned = _clean_entity_text(raw)
        if cleaned and len(cleaned) >= 2 and _is_likely_proper_noun(cleaned.split()[0]):
            return _capitalize_entity(cleaned)
        if cleaned and len(cleaned) >= 2:
            return cleaned[:1].upper() + cleaned[1:]
    return None


# ═══════════════════════════════════════════════════════════════
# MODE: CLASSIFICATION
# ═══════════════════════════════════════════════════════════════

def _build_classification_response(request_data, prompt):
    raw = _extract_text_from_payload(request_data, prompt)
    categories, type_map = _extract_categories_and_types(request_data, prompt)
    priorities = _extract_priorities(request_data, prompt)
    training_raw = _extract_training_samples(request_data, prompt)
    training = _align_training_samples(
        _augment_training_samples(training_raw),
        categories,
        type_map,
        priorities,
    )

    corrected = _auto_correct_text(raw)
    models = _train_all_models(training)

    # Decision matrix for priority
    intents = _detect_intents(corrected)
    it, ist = _infer_general_type_and_subtype(corrected)
    date_debut, _ = _extract_date_range(corrected)
    amt = _extract_amount(corrected)
    context = {
        "raw_text": corrected, "inferred_type": it, "inferred_subtype": ist,
        "days_until_deadline": _days_until(date_debut),
        "amount": float(amt) if amt else None,
    }
    matrix_priority, _, _ = _compute_priority_via_matrix(corrected, context)
    mapped_priority = _map_priority_to_allowed(matrix_priority, priorities)

    # Rule-based type scoring
    rule_scores = _score_all_types(corrected, categories, type_map)
    best_pair = max(rule_scores, key=rule_scores.get) if rule_scores else (None, None)
    rule_cat = best_pair[0] if best_pair[0] else (categories[0] if categories else "Autre")
    rule_type = best_pair[1] if best_pair[1] else type_map.get(rule_cat, ["Autre"])[0]
    rule_score = rule_scores.get(best_pair, 0) if best_pair else 0

    all_cats = list(categories)
    all_pris = [str(p).upper() for p in priorities]
    rule_cat_scores = _normalize_score_map({
        c: max((rule_scores.get((c, t), 0) for t in type_map.get(c, [])), default=0)
        for c in categories
    })

    final_cat = rule_cat
    final_type = rule_type
    final_priority = mapped_priority
    ml_conf = 0.0

    if models:
        ml_cat, cat_conf = _predict_with_ensemble(models, corrected, all_cats, "category")
        if ml_cat:
            combined = {
                c: 0.75 * (cat_conf if c == ml_cat else 0.0) + 0.25 * rule_cat_scores.get(c, 0.0)
                for c in all_cats
            }
            final_cat = max(combined, key=combined.get)

        allowed_types = type_map.get(final_cat, [])
        ml_type, type_conf = _predict_with_ensemble(models, corrected, allowed_types, "type")
        if ml_type and ml_type in allowed_types:
            rule_type_scores = _normalize_score_map({
                t: rule_scores.get((final_cat, t), 0) for t in allowed_types
            })
            combined = {
                t: 0.75 * (type_conf if t == ml_type else 0.0) + 0.25 * rule_type_scores.get(t, 0.0)
                for t in allowed_types
            }
            final_type = max(combined, key=combined.get)

        ml_pri, pri_conf = _predict_with_ensemble(models, corrected, all_pris, "priority")
        if ml_pri and pri_conf > 0.5:
            final_priority = ml_pri

        ml_conf = max(cat_conf, type_conf)

    avail = type_map.get(final_cat, [])
    if final_type not in avail and avail:
        final_type = avail[0]

    rule_conf = max(0.35, min(0.85, 0.45 + rule_score * 0.025))
    confidence = (
        round((0.40 * rule_conf) + (0.60 * (0.35 + 0.65 * ml_conf)), 2)
        if models else round(rule_conf, 2)
    )
    confidence = max(0.30, min(0.97, confidence))

    return {
        "correctedText": corrected,
        "categorie": final_cat,
        "typeDemande": final_type,
        "priorite": final_priority,
        "titre": _build_title(final_type, corrected),
        "description": corrected,
        "confidence": confidence,
    }


# ═══════════════════════════════════════════════════════════════
# MODE: DESCRIPTION
# ═══════════════════════════════════════════════════════════════

def _build_description_response(request_data, prompt):
    title_raw = _extract_json_value(prompt, "Titre: ")
    if title_raw is None:
        title_raw = request_data.get("titre", "") or request_data.get("title", "")
    title = _auto_correct_text(str(title_raw or "").strip()) or "Nouvelle demande"
    ct = re.sub(r"^(demande|besoin)\s+de\s+", "", title, flags=re.IGNORECASE).strip() or title
    description = _sentence_case(ct)
    if description and not re.search(r"[.!?]$", description):
        description += "."
    return {"description": _normalize_ws(description)}


# ═══════════════════════════════════════════════════════════════
# MODE: AUTRE
# ═══════════════════════════════════════════════════════════════

def _build_autre_response(request_data, prompt):
    context = _extract_json_value(prompt, "Contexte utilisateur: ")
    if not isinstance(context, dict):
        context = {}

    raw_source = (
        context.get("userPromptAutre")
        or context.get("descriptionGenerale")
        or context.get("titre")
        or _extract_text_from_payload(request_data, prompt)
        or ""
    )
    source = _strip_autre_prompt_boilerplate(_normalize_ws(raw_source))
    if not source:
        source = _strip_autre_prompt_boilerplate(_normalize_ws(prompt))

    explicit_urgency = _autre_detect_explicit_urgency(source)

    miner = _load_autre_pattern_miner()
    extractor = dyn_extract.BoundaryAwareExtractor()
    generator = dyn_extract.DynamicFieldGenerator()
    intents = generator.analyze_intent(source)
    rule_fields = extractor.extract_all_fields(source, intents)
    context_fields = _build_autre_structured_context(source, rule_fields, intents)
    structured_description = _build_structured_justification(context_fields, source)
    if explicit_urgency:
        base_priority_label = explicit_urgency["priority"]
        priority_confidence = 1.0
    else:
        base_priority_label, priority_confidence = miner.suggest_priority(source)

    title = _autre_build_title(source, rule_fields, intents)
    description = _autre_build_description(source, title)

    generated_plan = generator.generate_field_plan(source, intents, miner)
    custom_fields = []
    base_keys = {"besoinPersonnalise", "descriptionBesoin", "niveauUrgenceAutre", "dateSouhaiteeAutre"}
    seen_custom_keys = set()
    for field in generated_plan:
        if not isinstance(field, dict):
            continue
        key = _normalize_ws(field.get("key", ""))
        if not key or key in base_keys or key.endswith("_extra"):
            continue
        if generator._field_semantic_bucket(key) == "long_text" and _normalize_ws(field.get("source", "")) == "inferred":
            continue
        if key in seen_custom_keys:
            continue
        seen_custom_keys.add(key)
        raw_value = _normalize_ws(field.get("value", ""))
        cleaned_value = _autre_clean_field_value(key, raw_value, source, extractor)
        if not cleaned_value:
            if generator._field_semantic_bucket(key) != "long_text" and (
                _normalize_ws(field.get("source", "")) == "explicit" or not key.startswith("ai_custom_")
            ):
                predicted_value, predicted_confidence = miner.predict_field(key, source)
                if predicted_value and predicted_confidence >= 0.25 and _autre_can_apply_predicted_value(key, predicted_value, source):
                    cleaned_value = _autre_clean_field_value(key, predicted_value, source, extractor)
        if not cleaned_value and key in rule_fields:
            cleaned_value = _autre_clean_field_value(key, rule_fields.get(key, ""), source, extractor)
        if key == "ai_justification_metier" and len(_normalize_ws(structured_description)) > 20:
            cleaned_value = (
                structured_description
                if _structured_justification_preserves_evidence(source, structured_description)
                else _normalize_ws(source)
            )
        if key == "ai_zone_souhaitee" and not cleaned_value:
            continue
        if key.startswith("ai_custom_") and _normalize_ws(field.get("type", "")) == "select" and _normalize_ws(field.get("source", "")) != "explicit":
            cleaned_value = ""
        field["value"] = cleaned_value
        if "options" in field and not isinstance(field.get("options"), list):
            field["options"] = []
        custom_fields.append(field)

    # Miner corrections are applied after the rule-based extraction so the final
    # values stay boundary-safe even if the prompt is noisy.
    for field in custom_fields:
        key = _normalize_ws(field.get("key", ""))
        if not key:
            continue
        if generator._field_semantic_bucket(key) == "long_text":
            continue
        predicted_value, predicted_confidence = miner.predict_field(key, source)
        current_value = _normalize_ws(field.get("value", ""))
        cleaned_prediction = _autre_clean_field_value(key, predicted_value, source, extractor)
        if cleaned_prediction and _autre_can_apply_predicted_value(key, cleaned_prediction, source) and (
            not current_value
            or predicted_confidence >= 0.8
            or any(token in current_value.lower() for token in ["pour", "le", "du", "des", "vers"])
        ):
            field["value"] = cleaned_prediction

    title_details = dict(rule_fields)
    for field in custom_fields:
        if not isinstance(field, dict):
            continue
        key = _normalize_ws(field.get("key", ""))
        value = _normalize_ws(field.get("value", ""))
        if key and value:
            title_details[key] = value

    title = _autre_build_title(source, title_details, intents)
    description = _autre_build_description(source, title)

    def _upsert_custom_field(key, label, field_type, required, value, options=None):
        cleaned = _normalize_ws(value)
        for field in custom_fields:
            if _normalize_ws(field.get("key", "")) != key:
                continue
            field["value"] = cleaned
            if options is not None:
                field["options"] = list(options)
            return
        custom_fields.append({
            "key": key,
            "label": label,
            "type": field_type,
            "required": required,
            "value": cleaned,
            "options": list(options or []),
        })

    custom_fields = [
        field for field in custom_fields
        if not (
            _normalize_ws(field.get("key", "")) == "ai_lieu_depart_actuel"
            and not _normalize_ws(context_fields.get("lieu_depart_actuel", ""))
        )
    ]

    leave_signal = _has_any_word(_norm(source), ["conge", "congé", "arret", "arrêt", "absence", "repos"])

    if _normalize_ws(context_fields.get("lieu_souhaite", "")):
        _upsert_custom_field(
            "ai_lieu_souhaite",
            "Lieu souhaite",
            "text",
            False,
            context_fields.get("lieu_souhaite", ""),
        )

    if _normalize_ws(context_fields.get("date_debut", "")):
        _upsert_custom_field(
            "ai_date_souhaitee_metier",
            "Date demandee",
            "date",
            False,
            context_fields.get("date_debut", ""),
        )
        if leave_signal:
            _upsert_custom_field(
                "ai_date_debut_conge",
                "Date debut conge",
                "date",
                False,
                context_fields.get("date_debut", ""),
            )

    if _normalize_ws(context_fields.get("date_fin", "")):
        _upsert_custom_field(
            "ai_date_fin_extra",
            "Date fin",
            "date",
            False,
            context_fields.get("date_fin", ""),
        )
        if leave_signal:
            _upsert_custom_field(
                "ai_date_fin_conge",
                "Date fin conge",
                "date",
                False,
                context_fields.get("date_fin", ""),
            )

    if leave_signal:
        if _normalize_ws(rule_fields.get("ai_date_debut_conge", "")):
            _upsert_custom_field(
                "ai_date_debut_conge",
                "Date debut conge",
                "date",
                False,
                rule_fields.get("ai_date_debut_conge", ""),
            )
        if _normalize_ws(rule_fields.get("ai_date_fin_conge", "")):
            _upsert_custom_field(
                "ai_date_fin_conge",
                "Date fin conge",
                "date",
                False,
                rule_fields.get("ai_date_fin_conge", ""),
            )

    if len(_normalize_ws(structured_description)) > 20:
        justification_value = (
            structured_description
            if _structured_justification_preserves_evidence(source, structured_description)
            else _normalize_ws(source)
        )
        _upsert_custom_field(
            "ai_justification_metier",
            "Justification",
            "textarea",
            False,
            justification_value,
        )

    normalized_source = _norm(source)
    has_transport_signal = any(intent == "transport" and confidence > 0.08 for intent, confidence in intents) or _has_any_word(
        normalized_source,
        ["transport", "deplacement", "trajet", "mission", "navette", "bus", "train", "taxi", "voiture", "vehicule", "avion", "vol"],
    )
    explicit_transport_type = extractor._infer_transport_type(source)
    if has_transport_signal:
        _upsert_custom_field(
            "ai_type_transport",
            "Type de transport souhaite",
            "select",
            False,
            explicit_transport_type or "A definir",
            ["A definir", "Bus", "Train", "Voiture", "Vehicule", "Taxi", "Navette"],
        )

    if _has_any_phrase(normalized_source, ["parking", "stationnement", "place reservee", "place reserve", "acces parking", "badge parking"]):
        parking_zone = dyn_extract.extract_descriptive_location(source, "parking") or _extract_parking_zone(source)
        parking_type = _infer_parking_type(source) or "Place reservee"
        if parking_zone:
            _upsert_custom_field(
                "ai_zone_souhaitee",
                "Zone souhaitee",
                "text",
                True,
                parking_zone,
            )
        _upsert_custom_field(
            "ai_type_stationnement",
            "Type de stationnement",
            "select",
            True,
            parking_type,
            ["Place reservee", "Acces parking", "Autorisation temporaire", "Autre"],
        )

    custom_fields = _dedupe_and_refine_custom_fields(custom_fields, context_fields, source)

    details = {
        "besoinPersonnalise": title,
        "descriptionBesoin": rule_fields.get("descriptionBesoin") or description,
        "niveauUrgenceAutre": explicit_urgency["urgency"] if explicit_urgency else _autre_priority_to_base_label(base_priority_label, priority_confidence),
        "dateSouhaiteeAutre": context_fields.get("date_debut") or rule_fields.get("dateSouhaiteeAutre") or rule_fields.get("ai_date_souhaitee_metier") or extractor.extract_date(source) or "",
    }

    if not details["besoinPersonnalise"]:
        details["besoinPersonnalise"] = _autre_build_title(source, rule_fields, intents)

    if not details["descriptionBesoin"]:
        details["descriptionBesoin"] = description

    general = {
        "titre": title,
        "description": description,
        "priorite": explicit_urgency["priority"] if explicit_urgency else _autre_base_priority(base_priority_label),
    }

    if explicit_urgency:
        details["niveauUrgenceAutre"] = explicit_urgency["urgency"]
        general["priorite"] = explicit_urgency["priority"]

    return {
        "correctedText": source,
        "general": general,
        "details": details,
        "remove_fields": ["ALL"],
        "custom_fields": custom_fields,
        "replace_base": True,
    }


# ═══════════════════════════════════════════════════════════════
# MODE: GENERALIZED DEMANDE
# ═══════════════════════════════════════════════════════════════

def _build_generalized_demande_response(request_data, prompt):
    raw = _extract_text_from_payload(request_data, prompt)
    dataset_target = request_data.get("datasetTarget", 500)
    training_raw = _extract_training_samples(request_data, prompt)

    if not raw:
        return {
            "error": {"code": "EMPTY_INPUT", "message": "Texte vide."},
            "texte_corrige": "",
            "classification": {"type": None, "sous_type": None, "priorite": "MEDIUM"},
            "champs": {},
            "explication": "Aucune analyse possible.",
            "dataset_samples": _generate_dataset_samples(dataset_target),
        }

    corrected = _auto_correct_text(raw)
    intents = _detect_intents(corrected)
    it, ist = _infer_general_type_and_subtype(corrected)

    # Decision matrix priority
    date_debut, _ = _extract_date_range(corrected)
    amt = _extract_amount(corrected)
    context = {
        "raw_text": corrected, "inferred_type": it, "inferred_subtype": ist,
        "days_until_deadline": _days_until(date_debut),
        "amount": float(amt) if amt else None,
    }
    priorite, pri_conf, pri_dist = _compute_priority_via_matrix(corrected, context)

    # Context-aware field extraction
    champs = _build_context_fields(corrected, intents)

    # ML refinement
    general_training = _align_training_to_general_taxonomy(_augment_training_samples(training_raw))
    models = _train_all_models(general_training)
    ml_note = ""
    if models:
        all_types = list(GENERAL_TYPE_TAXONOMY.keys()) + ["autre"]
        ml_type, ml_conf = _predict_with_ensemble(models, corrected, all_types, "category")
        if ml_type and ml_conf >= 0.40 and ml_type != "autre":
            it = ml_type
            _, ist = _infer_general_type_and_subtype(corrected)
            ml_note = f" ML: {ml_type} ({ml_conf:.2f})."

    intent_str = ", ".join(f"{i[0]}({i[1]})" for i in intents[:3]) if intents else "aucun"

    return {
        "texte_corrige": corrected,
        "classification": {
            "type": it,
            "sous_type": ist,
            "priorite": priorite,
        },
        "champs": champs,
        "explication": (
            f"Intents detectes: {intent_str}. "
            f"Priorite: {priorite} (confiance {pri_conf}).{ml_note} "
            "Valeurs absentes restent NULL."
        ),
        "dataset_samples": _generate_dataset_samples(dataset_target),
    }


# ═══════════════════════════════════════════════════════════════
# MODE DETECTION
# ═══════════════════════════════════════════════════════════════

def _infer_mode(prompt, request_data):
    if request_data.get("analysisMode"):
        return "generalized_demande"
    if request_data.get("datasetTarget") or request_data.get("user_input") or request_data.get("texte_brut"):
        return "generalized_demande"
    normalized = _norm(prompt)
    gen_signals = [
        "dataset_samples", "texte_corrige", "gestion de demande",
        "output format (strict json)", "analyse", "classification multi",
    ]
    if sum(1 for s in gen_signals if s in normalized) >= 2:
        return "generalized_demande"
    if "correctedtext, categorie, typedemande, priorite, titre, description, confidence" in normalized:
        return "classification"
    if "une seule cle: description" in normalized:
        return "description"
    if "correctedtext, general, details, remove_fields, custom_fields, replace_base" in normalized:
        return "autre"
    for k in ["input", "text", "rawText", "userInput", "texte", "message"]:
        if isinstance(request_data.get(k), str) and request_data[k].strip():
            return "classification"
    return "unknown"


# ═══════════════════════════════════════════════════════════════
# ENTRY POINT
# ═══════════════════════════════════════════════════════════════

def _extract_location_pair(corrected_text):
    lowered = _norm(corrected_text)
    patterns = [
        r"\bde\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)(?=\s+(?:pour|afin|car|avec|le|la|une|un)\b|$)",
        r"\bdepuis\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)\s+vers\s+([a-zà-ÿ]{2,}(?:\s+[a-zà-ÿ]{2,})?)(?=\s+(?:pour|afin|car|avec|le|la|une|un)\b|$)",
        r"\bdepart\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b.*?\b(?:destination|arrivee)\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        raw_dep = _normalize_ws(match.group(1))
        raw_dest = _normalize_ws(match.group(2))
        dep_valid = any(_is_likely_proper_noun(w) for w in raw_dep.split())
        dest_valid = any(_is_likely_proper_noun(w) for w in raw_dest.split())
        if dep_valid and dest_valid and _norm(raw_dep) != _norm(raw_dest):
            return _capitalize_entity(raw_dep), _capitalize_entity(raw_dest)
    return None, None


def _extract_subject_name(corrected_text, subject_keyword):
    lowered = _norm(corrected_text)
    patterns = [
        rf"\b{re.escape(subject_keyword)}\s+(?:en\s+|sur\s+|de\s+)?([a-z][a-z0-9\s\-\./+#]*[a-z0-9+#])(?=\s+le\b|\s+du\b|\s+de\b|\s+vers\b|\s+pour\b|\s+a\b|$)",
        rf"\bpour\s+(?:une?\s+)?{re.escape(subject_keyword)}\s+(?:en\s+|sur\s+|de\s+)?([a-z][a-z0-9\s\-\./+#]*[a-z0-9+#])(?=\s+le\b|\s+du\b|\s+de\b|\s+vers\b|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        raw = match.group(1).strip()
        cleaned = _clean_entity_text(raw)
        cleaned = re.sub(r"^(?:professionnel(?:le)?|professionel)\s+(?:de|en|sur)\s+", "", cleaned, flags=re.IGNORECASE)
        cleaned = re.sub(r"^(?:professionnel(?:le)?|professionel)\s+", "", cleaned, flags=re.IGNORECASE)
        if cleaned and len(cleaned) >= 2 and _is_likely_proper_noun(cleaned.split()[0]):
            return _capitalize_entity(cleaned)
        if cleaned and len(cleaned) >= 2:
            return cleaned[:1].upper() + cleaned[1:]
    return None


def _extract_location_pair(corrected_text):
    lowered = _norm(corrected_text)
    stop_markers = [
        "uniquement", "seulement", "exclusivement", "en",
        "pour", "afin", "car", "avec", "le", "la", "les",
        "un", "une", "du", "de", "des",
    ]
    stop_pattern = "|".join(map(re.escape, stop_markers))
    patterns = [
        rf"\bde\s+([a-zà-ÿ]{{2,}}(?:\s+[a-zà-ÿ]{{2,}})?)\s+vers\s+([a-zà-ÿ]{{2,}}(?:\s+[a-zà-ÿ]{{2,}})?)(?=\s+(?:{stop_pattern})\b|\s+\d|$)",
        rf"\bdepuis\s+([a-zà-ÿ]{{2,}}(?:\s+[a-zà-ÿ]{{2,}})?)\s+vers\s+([a-zà-ÿ]{{2,}}(?:\s+[a-zà-ÿ]{{2,}})?)(?=\s+(?:{stop_pattern})\b|\s+\d|$)",
        r"\bdepart\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b.*?\b(?:destination|arrivee)\s*[:\-]?\s*([a-zà-ÿ]{3,}(?:\s+[a-zà-ÿ]{3,})?)\b",
    ]
    trailing_noise = re.compile(
        r"\b(?:uniquement|seulement|exclusivement|en|pour|afin|car|avec|le|la|les|un|une|du|de|des)\b.*$",
        re.IGNORECASE,
    )
    for pattern in patterns:
        match = re.search(pattern, lowered, flags=re.IGNORECASE)
        if not match:
            continue
        raw_dep = _normalize_ws(match.group(1))
        raw_dest = _normalize_ws(match.group(2))
        raw_dep = _normalize_ws(trailing_noise.sub("", raw_dep))
        raw_dest = _normalize_ws(trailing_noise.sub("", raw_dest))
        raw_dest = _normalize_ws(re.sub(r"\s+(?:en|uniquement|seulement|exclusivement)$", "", raw_dest, flags=re.IGNORECASE))
        dep_valid = any(_is_likely_proper_noun(w) for w in raw_dep.split())
        dest_valid = any(_is_likely_proper_noun(w) for w in raw_dest.split())
        if dep_valid and dest_valid and _norm(raw_dep) != _norm(raw_dest):
            return _capitalize_entity(raw_dep), _capitalize_entity(raw_dest)
    return None, None


def main():
    raw_input = sys.stdin.read()
    if not raw_input.strip():
        print(json.dumps({"ok": False, "error": "Missing JSON input"}))
        return
    try:
        request_data = json.loads(raw_input)
    except json.JSONDecodeError:
        print(json.dumps({"ok": False, "error": "Invalid JSON input"}))
        return

    prompt = str(request_data.get("prompt", "")).strip()

    try:
        mode = _infer_mode(prompt, request_data)
        if mode == "classification":
            result = _build_classification_response(request_data, prompt)
        elif mode == "description":
            result = _build_description_response(request_data, prompt)
        elif mode == "autre":
            result = _build_autre_response(request_data, prompt)
        elif mode == "generalized_demande":
            result = _build_generalized_demande_response(request_data, prompt)
        else:
            raw = _extract_text_from_payload(request_data, prompt)
            if raw:
                result = _build_classification_response(request_data, prompt)
            else:
                result = {"message": _normalize_ws(prompt), "mode": "unknown"}
        text = json.dumps(result, ensure_ascii=False)
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        return

    print(json.dumps({"ok": True, "text": text}, ensure_ascii=False))


if __name__ == "__main__":
    main()
