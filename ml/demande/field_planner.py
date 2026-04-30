from __future__ import annotations

from collections import Counter, defaultdict
from dataclasses import replace
import re
from typing import Any, Iterable

from extractors import (
    ExtractedEntities,
    MATERIAL_TERMS,
    contains_term,
    extract_entities,
    extract_first_number,
    extract_period_label,
    extract_transport_mode,
    has_any_word,
    has_phrase,
    has_word,
    norm,
    normalize_ws,
    sentence_case,
    smart_title,
    tokenize,
)
from learning_store import (
    FieldSpec,
    LearningProfile,
    build_learning_profile,
    infer_field_type,
    is_long_text_key,
    label_from_key,
    should_suppress_field,
)
from retrieval import MIN_SCHEMA_MATCH_SCORE, RetrievalMatch, RetrievalResult, field_role, rank_requests, retrieval_stats


MAX_FIELDS = 8


def _field(key: str, label: str, field_type: str = "text", required: bool = False, value: str = "", options: Iterable[str] = (), source: str = "explicit") -> FieldSpec:
    return FieldSpec(
        key=key,
        label=label,
        type=field_type,
        required=required,
        value=normalize_ws(value),
        options=tuple(normalize_ws(option) for option in options if normalize_ws(option)),
        source=source,
        explicit=True,
        confidence=0.72 if value else 0.45,
    )


def value_has_prompt_evidence(source: str, value: str) -> bool:
    clean = norm(source)
    value_norm = norm(value)
    if not clean or not value_norm:
        return False
    if contains_term(clean, value_norm):
        return True

    ignored = {
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
    tokens = [token for token in tokenize(value_norm, include_bigrams=False) if len(token) >= 3 and token not in ignored]
    if not tokens:
        return False
    if len(tokens) == 1:
        return contains_term(clean, tokens[0])
    return all(contains_term(clean, token) for token in tokens)


def _select_value_from_options(value: str, options: Iterable[str], source: str) -> str:
    clean_value = norm(value)
    clean_source = norm(source)
    option_list = [normalize_ws(option) for option in options if normalize_ws(option)]
    if not option_list:
        return normalize_ws(value)
    if not clean_value:
        return ""

    for option in option_list:
        if norm(option) == clean_value and contains_term(clean_source, option):
            return option

    for option in option_list:
        option_tokens = [token for token in tokenize(option, include_bigrams=False) if len(token) >= 3 and token not in {"type", "autre", "demande"}]
        if option_tokens and all(contains_term(clean_source, token) for token in option_tokens):
            return option

    semantic_evidence = {
        "transport": ["taxi", "bus", "train", "avion", "vol", "navette"],
        "hotel": ["hotel", "hebergement", "nuit", "nuitee"],
        "restaurant": ["restaurant", "repas", "dejeuner", "diner"],
        "internet": ["internet", "connexion", "wifi"],
        "medicaments": ["medicament", "medicaments", "pharmacie"],
    }
    for option in option_list:
        option_norm = norm(option)
        terms = semantic_evidence.get(option_norm)
        if terms and has_any_word(clean_source, terms):
            return option

    # A select must not receive a non-empty default just because it exists in history.
    return ""


def _extract_named_value(source: str, field: FieldSpec) -> str:
    label = normalize_ws(field.label)
    if not label:
        return ""
    pattern = r"\b" + re.escape(label) + r"\s*[:=-]\s*(.+?)(?=[,.;]|$)"
    match = re.search(pattern, source, flags=re.IGNORECASE)
    if not match:
        return ""
    return normalize_ws(match.group(1)).strip(" ,;:-")[:120]


def _extract_room(source: str) -> str:
    match = re.search(r"\bsalle\s+([A-Za-zÀ-ÿ0-9][A-Za-zÀ-ÿ0-9'\- ]{1,40})(?=\s+(?:pour|le|la|les|du|de|des|a|à|avec)\b|[,.;]|$)", source, flags=re.IGNORECASE)
    return smart_title(match.group(1)) if match else ""


def _extract_access_system(source: str) -> str:
    clean = norm(source)
    known = ["vpn", "crm", "jira", "salesforce", "sap", "gitlab", "github", "badge"]
    for item in known:
        if has_word(clean, item):
            return item.upper() if item in {"vpn", "crm", "sap"} else sentence_case(item)
    match = re.search(r"\b(?:acces|accès)\s+(?:a|à|au|sur)?\s*([A-Za-zÀ-ÿ0-9][A-Za-zÀ-ÿ0-9'\- ]{1,40})(?=\s+(?:pour|le|la|les|du|de|des|avec)\b|[,.;]|$)", source, flags=re.IGNORECASE)
    return smart_title(match.group(1)) if match else ""


def _extract_access_type(source: str, options: Iterable[str] = ()) -> str:
    clean = norm(source)
    if has_any_word(clean, ["admin", "administrateur"]):
        candidate = "Administrateur"
    elif has_phrase(clean, "lecture seule") or has_any_word(clean, ["consultation"]):
        candidate = "Lecture seule"
    elif has_any_word(clean, ["ecriture", "écriture", "modifier"]):
        candidate = "Lecture/Ecriture"
    else:
        candidate = ""
    return _select_value_from_options(candidate, options, source) if options else candidate


def _extract_intervention_value(source: str, key_text: str) -> str:
    clean = norm(source)
    if any(token in key_text for token in ["type", "intervention"]):
        if has_any_word(clean, ["nettoyage", "nettoyer"]):
            return "Nettoyage"
        if has_any_word(clean, ["maintenance", "reparation", "réparation"]):
            return "Maintenance"
        if has_any_word(clean, ["clim", "climatisation"]):
            return "Climatisation"
        if has_any_word(clean, ["serrure", "porte"]):
            return "Serrure"
    if any(token in key_text for token in ["nature", "incident", "degat", "dégat"]):
        match = re.search(r"\b(cafe\s+renverse|café\s+renversé|panne\s+[A-Za-zÀ-ÿ'\- ]{2,40}|fuite\s+[A-Za-zÀ-ÿ'\- ]{2,40})", source, flags=re.IGNORECASE)
        if match:
            return smart_title(match.group(1))
    if any(token in key_text for token in ["surface", "element", "equipement"]):
        for item in ["moquette", "climatisation", "clim", "serrure", "porte", "bureau", "sol", "machine a cafe", "machine à café"]:
            if has_phrase(clean, item):
                return smart_title(item)
    return ""


def _extract_shift_value(source: str, with_prefix: bool = False) -> str:
    clean = norm(source)
    if has_any_word(clean, ["nuit", "nocturne"]):
        value = "Nuit"
    elif has_any_word(clean, ["soir", "soiree"]):
        value = "Soir"
    elif has_any_word(clean, ["jour", "journee", "matin"]):
        value = "Jour"
    else:
        return ""
    return f"Shift de {value.lower()}" if with_prefix else value


def _extract_period_label(source: str) -> str:
    clean = norm(source)
    recurring = extract_period_label(source)
    if recurring:
        return recurring
    if has_phrase(clean, "semaine prochaine"):
        return "Semaine prochaine"
    if has_phrase(clean, "cette semaine") or has_phrase(clean, "semaine en cours"):
        return "Semaine en cours"
    if has_phrase(clean, "mois prochain"):
        return "Mois prochain"
    if has_phrase(clean, "ce mois") or has_phrase(clean, "mois en cours"):
        return "Mois en cours"
    if has_word(clean, "demain"):
        return "Demain"
    return ""


def _has_maintenance_signal(source: str) -> bool:
    return has_any_word(
        source,
        ["maintenance", "nettoyage", "nettoyer", "reparation", "réparation", "clim", "serrure", "panne", "degat", "dégat", "dégât", "cafe", "café", "renverse", "renversé"],
    )


def _has_schedule_signal(source: str) -> bool:
    return has_any_word(source, ["horaire", "shift", "poste"])


def _has_material_signal(source: str) -> bool:
    clean = norm(source)
    if has_any_word(clean, ["materiel", "equipement", "outil", "accessoire", "peripherique"]):
        return True
    return any(has_phrase(clean, term) or has_word(clean, term) for term in MATERIAL_TERMS)


def _object_supported_by_source(source: str, entities: ExtractedEntities) -> bool:
    if not entities.requested_object or _has_maintenance_signal(source):
        return False
    if _has_schedule_signal(source) and not _has_material_signal(source):
        return False
    return True


def _period_blocks_generic_date(source: str) -> bool:
    period = _extract_period_label(source)
    return period.startswith("Tous les ")


def _period_value_for_source(source: str, entities: ExtractedEntities) -> str:
    period = _extract_period_label(source)
    if period.startswith("Tous les "):
        return period
    if entities.date_start and entities.date_end and entities.date_end != entities.date_start:
        return f"{entities.date_start} - {entities.date_end}"
    if entities.date_start:
        return entities.date_start
    return period


def _date_supported_by_source(source: str, field: FieldSpec, entities: ExtractedEntities) -> bool:
    if not entities.date_start:
        return False
    key_text = norm(f"{field.key} {field.label}")
    if _period_blocks_generic_date(source) and not any(token in key_text for token in ["conge", "absence", "debut", "fin"]):
        return False
    return True


def _is_current_time_field(field: FieldSpec) -> bool:
    key_text = norm(f"{field.key} {field.label}")
    return any(token in key_text for token in ["actuel", "ancien"])


def _time_supported_by_source(source: str, field: FieldSpec, entities: ExtractedEntities) -> bool:
    key_text = norm(f"{field.key} {field.label}")
    if "shift" in key_text and "horaire" not in key_text:
        return bool(_extract_shift_value(source, with_prefix=False))
    if any(token in key_text for token in ["debut", "dÃ©but"]):
        return bool(entities.time_start)
    if _is_current_time_field(field):
        return bool(entities.schedule_current)
    if any(token in key_text for token in ["fin", "sortie"]):
        return bool(entities.time_end)
    return bool(entities.time_range or entities.schedule_target)


def _extract_category_value(source: str, field: FieldSpec, entities: ExtractedEntities) -> str:
    key_text = norm(f"{field.key} {field.label}")

    if "formation" in key_text and "type" in key_text:
        if has_word(source, "certification"):
            candidate = "Certification"
        elif has_word(source, "externe"):
            candidate = "Formation externe"
        elif has_word(source, "interne"):
            candidate = "Formation interne"
        else:
            candidate = ""
        return _select_value_from_options(candidate, field.options, source) if field.type == "select" else candidate

    if "transport" in key_text or "moyen" in key_text or "vehicule" in key_text:
        candidate = extract_transport_mode(source, field.options)
        return _select_value_from_options(candidate, field.options, source) if field.type == "select" else candidate

    if "shift" in key_text:
        return _extract_shift_value(source, with_prefix=False)

    if "type" in key_text and "demande" in key_text and has_word(source, "shift"):
        return _extract_shift_value(source, with_prefix=True)

    if "stationnement" in key_text or "parking" in key_text:
        if has_phrase(source, "place reservee") or has_phrase(source, "place réservée"):
            candidate = "Place reservee"
        elif has_phrase(source, "badge parking") or has_phrase(source, "acces parking") or has_phrase(source, "accès parking"):
            candidate = "Acces parking"
        elif has_any_word(source, ["temporaire", "provisoire"]):
            candidate = "Autorisation temporaire"
        else:
            candidate = ""
        return _select_value_from_options(candidate, field.options, source) if field.type == "select" else candidate

    if "depense" in key_text or "frais" in key_text or "remboursement" in key_text:
        candidate = entities.expense_type
        return _select_value_from_options(candidate, field.options, source) if field.type == "select" else norm(candidate)

    if "conge" in key_text or "absence" in key_text:
        candidate = entities.leave_type
        return _select_value_from_options(candidate, field.options, source) if field.type == "select" else candidate

    if any(token in key_text for token in ["type", "nature", "categorie"]):
        return _extract_intervention_value(source, key_text)

    return ""


def extract_value_for_field(source: str, field: FieldSpec, entities: ExtractedEntities, examples: Iterable[FieldSpec] = ()) -> tuple[str, bool]:
    key_text = norm(f"{field.key} {field.label}")
    role = field_role(field)

    # Historical values are reusable only when the same value is visibly present.
    for example in examples:
        generic_custom_object = field.key.startswith("ai_custom_") and "objet" in key_text
        generic_value = norm(example.value) in {"demande", "demande transport", "demande de transport", "transport", "trajet", "deplacement"}
        role_supported = True
        if role == "date":
            role_supported = _date_supported_by_source(source, field, entities)
        elif role == "object":
            role_supported = _object_supported_by_source(source, entities)
        elif role == "time":
            role_supported = _time_supported_by_source(source, field, entities)
        if (
            role != "organization"
            and role_supported
            and not generic_custom_object
            and not generic_value
            and example.key == field.key
            and example.value
            and value_has_prompt_evidence(source, example.value)
            and not is_long_text_key(example.key, example.label)
        ):
            value = normalize_ws(example.value)
            if role == "period":
                value = _period_value_for_source(source, entities) or value
            if field.type == "select":
                value = _select_value_from_options(value, field.options or example.options, source)
            return value, bool(value)

    if "systeme" in key_text or "logiciel" in key_text or "application" in key_text:
        value = _extract_access_system(source)
        return value, bool(value)

    if "type" in key_text and ("acces" in key_text or "accès" in key_text):
        value = _extract_access_type(source, field.options)
        return value, bool(value)

    if role == "date":
        if not _date_supported_by_source(source, field, entities):
            return "", False
        return entities.date_start, True
    if role == "number":
        value = entities.amount or extract_first_number(source)
        return value, bool(value)
    if role == "route_from":
        return entities.route_from, bool(entities.route_from)
    if role == "route_to":
        return entities.route_to, bool(entities.route_to)
    if role == "location":
        value = entities.parking_zone or entities.route_to or entities.route_from
        return value, bool(value)
    if role == "constraint":
        value = entities.constraints[0] if entities.constraints else ""
        return value, bool(value)
    if role == "transport_mode":
        value = extract_transport_mode(source, field.options)
        if field.type == "select":
            value = _select_value_from_options(value, field.options, source)
        return value, bool(value)
    if role == "training":
        if "type" in key_text:
            value = _extract_category_value(source, field, entities)
        else:
            value = entities.training_name
        return value, bool(value)
    if role == "attestation":
        return entities.attestation_type, bool(entities.attestation_type)
    if role == "organization":
        return entities.organization, bool(entities.organization)
    if role == "object":
        if not _object_supported_by_source(source, entities):
            return "", False
        return entities.requested_object, True
    if role == "specification":
        return entities.specification, bool(entities.specification)
    if role == "category":
        value = _extract_category_value(source, field, entities)
        if field.type == "select":
            value = _select_value_from_options(value, field.options, source)
        return value, bool(value)
    if role == "reason":
        return entities.reason, bool(entities.reason)
    if role == "room":
        value = _extract_room(source)
        return value, bool(value)
    if role == "time":
        if "shift" in key_text and "horaire" not in key_text:
            value = _extract_shift_value(source, with_prefix=False)
            return value, bool(value)
        if any(token in key_text for token in ["debut", "début"]):
            return entities.time_start, bool(entities.time_start)
        if any(token in key_text for token in ["actuel", "ancien"]):
            return entities.schedule_current, bool(entities.schedule_current)
        if any(token in key_text for token in ["fin", "sortie"]):
            return entities.time_end, bool(entities.time_end)
        return entities.time_range or entities.schedule_target, bool(entities.time_range or entities.schedule_target)
    if role == "period":
        value = _period_value_for_source(source, entities)
        return value, bool(value)

    intervention = _extract_intervention_value(source, key_text)
    if intervention:
        return intervention, True

    named = _extract_named_value(source, field)
    if named:
        return named, True

    return "", False


def _field_named_explicitly(source: str, field: FieldSpec) -> bool:
    clean = norm(source)
    tokens = [
        token
        for token in tokenize(f"{field.key} {field.label}", include_bigrams=False)
        if token not in {"ai", "custom", "champ", "demande", "souhaite", "souhaitee", "type", "autre", "concerne", "transport"}
    ]
    return bool(tokens and any(re.search(rf"\b{re.escape(token)}\b", clean) for token in tokens))


def _source_supports_field(source: str, field: FieldSpec, entities: ExtractedEntities) -> bool:
    role = field_role(field)
    if role == "date":
        return _date_supported_by_source(source, field, entities)
    if role == "number":
        return bool(entities.amount)
    if role == "route_from":
        return bool(entities.route_from)
    if role == "route_to":
        return bool(entities.route_to)
    if role == "location":
        return bool(entities.route_from or entities.route_to or entities.parking_zone)
    if role == "constraint":
        return bool(entities.constraints)
    if role == "transport_mode":
        return bool(entities.transport_mode)
    if role == "training":
        return bool(entities.training_name or has_any_word(source, ["formation", "certification", "cours", "atelier"]))
    if role == "attestation":
        return bool(entities.attestation_type)
    if role == "organization":
        return bool(entities.organization)
    if role == "object":
        return _object_supported_by_source(source, entities)
    if role == "specification":
        return bool(entities.specification)
    if role == "category":
        return bool(
            entities.expense_type
            or entities.leave_type
            or entities.transport_mode
            or has_any_word(source, ["parking", "stationnement", "shift", "horaire", "nettoyage", "maintenance"])
        )
    if role == "reason":
        return bool(entities.reason)
    if role == "room":
        return has_any_word(source, ["salle", "room", "reservation"])
    if role == "time":
        return _time_supported_by_source(source, field, entities)
    if role == "period":
        return bool(_extract_period_label(source) or entities.date_start or entities.date_end)
    return False


def _roles_compatible(anchor: RetrievalMatch, candidate: RetrievalMatch) -> bool:
    if candidate is anchor:
        return True
    anchor_roles = {field_role(field) for field in anchor.fields if field_role(field) != "generic"}
    candidate_roles = {field_role(field) for field in candidate.fields if field_role(field) != "generic"}
    if not anchor_roles or not candidate_roles:
        return False
    overlap = anchor_roles & candidate_roles
    coverage = len(overlap) / max(1, min(len(anchor_roles), len(candidate_roles)))
    return coverage >= 0.50 and candidate.score >= max(0.35, anchor.score * 0.72)


def _candidate_schema_fields(result: RetrievalResult, profile: LearningProfile) -> list[tuple[FieldSpec, list[FieldSpec], float, bool]]:
    if not result.matches:
        return []
    anchor = result.matches[0]
    compatible = [match for match in result.matches if _roles_compatible(anchor, match)]
    anchor_keys = {field.key for field in anchor.fields}

    collected: dict[str, dict[str, Any]] = {}
    order: dict[str, tuple[int, int]] = {}
    for match_index, match in enumerate(compatible):
        for field_index, field in enumerate(match.fields):
            if not field.key:
                continue
            from_anchor = field.key in anchor_keys
            cooccurs_with_anchor = any(profile.field_cooccurrence.get(anchor_key, Counter()).get(field.key, 0) for anchor_key in anchor_keys)
            if not from_anchor and not cooccurs_with_anchor:
                continue
            if field.key not in collected:
                collected[field.key] = {
                    "field": field,
                    "examples": [],
                    "weight": 0.0,
                    "from_anchor": from_anchor,
                }
                order[field.key] = (match_index, field_index)
            collected[field.key]["examples"].extend(match.fields)
            weight = match.score * (1.5 if match.sample.manual else 1.0)
            collected[field.key]["weight"] += weight
            collected[field.key]["from_anchor"] = collected[field.key]["from_anchor"] or from_anchor

    rows = [
        (data["field"], data["examples"], float(data["weight"]), bool(data["from_anchor"]))
        for key, data in collected.items()
    ]
    rows.sort(key=lambda item: (order.get(item[0].key, (99, 99))[0], order.get(item[0].key, (99, 99))[1], item[0].key))
    return rows


def _dedupe_fields(fields: Iterable[FieldSpec]) -> list[FieldSpec]:
    seen: set[str] = set()
    deduped: list[FieldSpec] = []
    for field in fields:
        key = normalize_ws(field.key)
        if not key or key in seen:
            continue
        seen.add(key)
        deduped.append(field)
    return deduped


def _duplicate_value_keep_score(field: FieldSpec) -> float:
    key_text = norm(f"{field.key} {field.label}")
    role = field_role(field)
    score = field.confidence
    if field.required:
        score += 2.0
    if field.source == "explicit":
        score += 1.2
    if field.source == "manual":
        score += 0.8
    if field.source == "seed":
        score += 0.4
    if field.type == "textarea":
        score -= 0.8
    if field.type == "date":
        score += 3.0
    if role == "period":
        score -= 0.5
    if role in {"object", "specification", "attestation", "organization", "route_from", "route_to", "transport_mode"}:
        score += 1.0
    if role in {"reason", "generic"}:
        score -= 0.6
    if field.key.startswith("ai_custom_"):
        score -= 1.0
    if any(token in key_text for token in ["extra", "infos", "information", "description", "detail", "justification"]):
        score -= 0.9
    if "motif" in key_text:
        score += 0.4
    if any(token in key_text for token in ["materiel", "equipement", "systeme", "type", "nom", "montant"]):
        score += 0.5
    return score


def _dedupe_same_values(fields: Iterable[FieldSpec]) -> list[FieldSpec]:
    field_list = list(fields)
    groups: dict[str, list[FieldSpec]] = {}
    dedupe_excluded_roles = {"route_from", "route_to"}

    for field in field_list:
        value = normalize_ws(field.value)
        value_key = norm(value)
        if (
            not value_key
            or len(value_key) < 3
            or field_role(field) in dedupe_excluded_roles
            or re.fullmatch(r"\d+(?:[.,]\d+)?", value_key)
        ):
            continue
        groups.setdefault(value_key, []).append(field)

    keep_keys: set[str] = set()
    drop_keys: set[str] = set()
    for duplicates in groups.values():
        if len(duplicates) < 2:
            continue
        keeper = max(duplicates, key=_duplicate_value_keep_score)
        keep_keys.add(keeper.key)
        for field in duplicates:
            if field.key != keeper.key:
                drop_keys.add(field.key)

    if not drop_keys:
        return field_list

    return [field for field in field_list if field.key not in drop_keys or field.key in keep_keys]


def _trim_fields(fields: list[FieldSpec], max_fields: int = MAX_FIELDS) -> list[FieldSpec]:
    if len(fields) <= max_fields:
        return fields
    explicit = [field for field in fields if field.explicit or normalize_ws(field.value)]
    inferred = [field for field in fields if field not in explicit]
    return (explicit + inferred)[:max(len(explicit), max_fields)]


def plan_from_retrieval(source: str, result: RetrievalResult, profile: LearningProfile) -> list[FieldSpec]:
    entities = result.entities
    planned: list[FieldSpec] = []

    for field, examples, weight, from_anchor in _candidate_schema_fields(result, profile):
        value, explicit_value = extract_value_for_field(source, field, entities, examples)
        named_explicit = _field_named_explicitly(source, field)

        if field.type == "select":
            value = _select_value_from_options(value, field.options, source)

        if not from_anchor and not named_explicit and field_role(field) == "generic":
            continue

        explicit = bool(value) or named_explicit
        inferred_supported = explicit or named_explicit or (from_anchor and _source_supports_field(source, field, entities))
        if not inferred_supported:
            continue

        candidate = replace(
            field,
            value=value,
            explicit=explicit,
            confidence=min(0.99, max(result.best_score, weight / 3)),
            source=field.source or "learned",
        )
        if should_suppress_field(candidate, profile, source) and not named_explicit:
            continue
        planned.append(candidate)

    return _trim_fields(_dedupe_same_values(_dedupe_fields(planned)))


def _merge_explicit_evidence_fields(fields: Iterable[FieldSpec], evidence_fields: Iterable[FieldSpec], source: str, entities: ExtractedEntities) -> list[FieldSpec]:
    merged = list(fields)
    positions = {field.key: index for index, field in enumerate(merged)}

    for evidence in evidence_fields:
        if not normalize_ws(evidence.key):
            continue
        supported = bool(normalize_ws(evidence.value)) or _source_supports_field(source, evidence, entities)
        if not supported:
            continue
        if evidence.key in positions:
            current_index = positions[evidence.key]
            current = merged[current_index]
            if not normalize_ws(current.value) and normalize_ws(evidence.value):
                merged[current_index] = replace(
                    current,
                    value=evidence.value,
                    explicit=True,
                    confidence=max(current.confidence, evidence.confidence),
                    source=current.source or evidence.source,
                )
            continue
        evidence_value_key = norm(evidence.value)
        evidence_role = field_role(evidence)
        if evidence_value_key:
            duplicate_existing = next((existing for existing in merged if norm(existing.value) == evidence_value_key), None)
            if duplicate_existing is not None and field_role(duplicate_existing) != "reason":
                continue
        positions[evidence.key] = len(merged)
        merged.append(evidence)

    return _trim_fields(_dedupe_same_values(_dedupe_fields(merged)))


def fallback_fields(source: str, entities: ExtractedEntities) -> tuple[list[FieldSpec], bool]:
    fields: list[FieldSpec] = []

    if entities.route_from or entities.route_to or entities.transport_mode:
        if entities.route_from:
            fields.append(_field("ai_lieu_depart_actuel", "Lieu de depart", "text", True, entities.route_from))
        if entities.route_to:
            fields.append(_field("ai_lieu_souhaite", "Destination", "text", True, entities.route_to))
        fields.append(_field("ai_type_transport", "Type de transport", "select", False, entities.transport_mode, ["Bus", "Train", "Taxi", "Voiture de service", "Navette"]))
        if entities.constraints:
            fields.append(_field("ai_contrainte", "Contrainte", "text", False, entities.constraints[0]))

    if has_any_word(source, ["parking", "stationnement"]):
        if entities.parking_zone:
            fields.append(_field("ai_zone_souhaitee", "Zone souhaitee", "text", True, entities.parking_zone))
        fields.append(_field("ai_type_stationnement", "Type de stationnement", "select", True, "", ["Place reservee", "Acces parking", "Autorisation temporaire", "Autre"]))

    if has_any_word(source, ["remboursement", "frais", "depense", "dépense", "note"]):
        if entities.expense_type:
            fields.append(_field("ai_type_depense", "Type de depense", "select", True, entities.expense_type, ["Transport", "Restaurant", "Hotel", "Internet", "Medicaments", "Autre"]))
        if entities.amount:
            fields.append(_field("ai_montant", "Montant", "number", True, entities.amount))
        if entities.reason:
            fields.append(_field("ai_justification_metier", "Justification", "textarea", False, entities.reason))

    if has_any_word(source, ["acces", "accès", "vpn", "crm", "jira", "badge"]):
        system = _extract_access_system(source)
        if system:
            fields.append(_field("ai_systeme_concerne", "Systeme concerne", "text", True, system))
        access_type = _extract_access_type(source, ["Lecture seule", "Lecture/Ecriture", "Administrateur", "Autre"])
        fields.append(_field("ai_type_acces", "Type d acces", "select", False, access_type, ["Lecture seule", "Lecture/Ecriture", "Administrateur", "Autre"]))
        if entities.reason:
            fields.append(_field("ai_justification_metier", "Justification", "textarea", False, entities.reason))

    maintenance_signal = _has_maintenance_signal(source)

    if _has_material_signal(source) and entities.requested_object and not maintenance_signal and not has_any_word(source, ["remboursement", "transport", "parking", "attestation"]):
        fields.append(_field("ai_materiel_concerne", "Materiel concerne", "text", True, entities.requested_object))
        if entities.specification:
            fields.append(_field("ai_specification", "Specification", "text", False, entities.specification))
        if entities.reason:
            fields.append(_field("ai_justification_metier", "Justification", "textarea", False, entities.reason))

    if maintenance_signal:
        intervention = _extract_intervention_value(source, "type intervention")
        if intervention:
            fields.append(_field("ai_type_intervention", "Type intervention", "text", True, intervention))
        nature = _extract_intervention_value(source, "nature incident degat")
        if nature:
            fields.append(_field("ai_nature_incident", "Nature incident", "text", False, nature))
        equipment = _extract_intervention_value(source, "surface element equipement")
        if equipment:
            fields.append(_field("ai_equipement_concerne", "Equipement concerne", "text", False, equipment))

    if entities.attestation_type:
        fields.append(_field("ai_type_attestation", "Type d attestation", "text", True, entities.attestation_type))
        if entities.organization:
            fields.append(_field("ai_organisme_destinataire", "Organisme destinataire", "text", False, entities.organization))
        elif entities.reason:
            fields.append(_field("ai_motif_contexte", "Motif contexte", "text", False, entities.reason))

    if entities.leave_type:
        fields.append(_field("ai_type_conge", "Type de conge", "select", True, entities.leave_type, ["Conge annuel", "Conge maladie", "Conge sans solde", "Autre"]))
        if entities.date_start:
            fields.append(_field("ai_date_debut_conge", "Date debut conge", "date", True, entities.date_start))
        if entities.date_end:
            fields.append(_field("ai_date_fin_conge", "Date fin conge", "date", True, entities.date_end))
        if entities.reason:
            fields.append(_field("ai_motif_conge", "Motif", "textarea", False, entities.reason))

    if _has_schedule_signal(source):
        shift_value = _extract_shift_value(source, with_prefix=False)
        if shift_value:
            fields.append(_field("ai_type_demande", "Type de demande", "text", True, _extract_shift_value(source, with_prefix=True)))
            fields.append(_field("ai_shift_souhaite", "Shift souhaite", "text", True, shift_value))
        if entities.schedule_current:
            fields.append(_field("ai_horaire_actuel", "Horaire actuel", "text", False, entities.schedule_current))
        if entities.schedule_target:
            fields.append(_field("ai_horaire_souhaite", "Horaire souhaite", "text", True, entities.schedule_target))
        period = _extract_period_label(source)
        if period:
            if period.startswith("Tous les "):
                fields.append(_field("ai_periode_concernee", "Periode concernee", "text", False, period))
            elif entities.date_start:
                fields.append(_field("ai_date_souhaitee", "Date souhaitee", "date", False, entities.date_start))
            else:
                fields.append(_field("ai_periode_concernee", "Periode concernee", "text", False, period))
        if entities.reason:
            fields.append(_field("ai_motif_changement", "Motif changement", "textarea", False, entities.reason))

    if entities.training_name:
        fields.append(_field("ai_nom_formation", "Nom de la formation", "text", True, entities.training_name))
        training_type = "Certification" if has_word(source, "certification") else ""
        fields.append(_field("ai_type_formation", "Type de formation", "select", False, training_type, ["Formation interne", "Formation externe", "Certification"]))

    if entities.date_start and not any(field.type == "date" for field in fields) and not _period_blocks_generic_date(source):
        fields.append(_field("ai_date_souhaitee", "Date souhaitee", "date", False, entities.date_start))

    fields = _dedupe_same_values(_dedupe_fields(fields))
    if fields:
        return _trim_fields(fields), True

    # This is intentionally weak: it is useful for UI continuity, but it should
    # still request LLM fallback if the caller has one configured.
    return [_field("ai_description_libre", "Description libre", "textarea", True, normalize_ws(source)[:220], source="fallback")], False


def priority(source: str) -> tuple[str, str]:
    clean = norm(source)
    if re.search(r"\b(tres urgent|très urgent|urgent|urgence|bloquant|immediat|immédiat|immediatement|immédiatement|critique)\b", clean):
        return "HAUTE", "Urgente"
    if re.search(r"\b(pas urgent|quand possible|faible priorite|faible priorité)\b", clean):
        return "BASSE", "Faible"
    return "NORMALE", "Normale"


def build_title(source: str, fields: Iterable[FieldSpec]) -> str:
    field_list = list(fields)
    for field in field_list:
        key_text = norm(f"{field.key} {field.label}")
        value = normalize_ws(field.value)
        if not value:
            continue
        if field.key == "ai_type_demande" and value:
            return sentence_case(value)[:100]
        if "shift" in key_text and value:
            shift_value = value if value.lower().startswith("shift") else f"Shift de {value.lower()}"
            return sentence_case(shift_value)[:100]
        if "materiel" in key_text or "equipement" in key_text:
            return sentence_case(f"Demande de materiel {value}")[:100]
        if "attestation" in key_text:
            return sentence_case(value)[:100]
        if "transport" in key_text or "destination" in key_text:
            route_from = next((item.value for item in field_list if item.key == "ai_lieu_depart_actuel" and item.value), "")
            route_to = next((item.value for item in field_list if item.key == "ai_lieu_souhaite" and item.value), "")
            if route_from and route_to:
                return f"Transport {route_from} vers {route_to}"[:100]
        if "formation" in key_text:
            return sentence_case(f"Demande de formation {value}")[:100]
    return sentence_case(normalize_ws(source)[:90] or "Demande personnalisee")


def confidence_payload(score: float, used_retrieval: bool, useful_local_plan: bool, manual_schema: bool = False) -> dict[str, Any]:
    if not used_retrieval:
        if useful_local_plan:
            return {
                "score": 45,
                "label": "Moyenne",
                "tone": "info",
                "message": "Aucun historique proche: plan construit depuis les evidences du prompt.",
            }
        return {
            "score": 20,
            "label": "Faible",
            "tone": "info",
            "message": "Aucun historique exploitable et peu d evidences structurees: fallback LLM recommande.",
        }

    pct = max(35, min(99, int(round(score * 100))))
    if manual_schema:
        pct = max(pct, 86)
    if pct >= 72:
        return {
            "score": pct,
            "label": "Elevee",
            "tone": "success",
            "message": "Schema appris depuis des demandes confirmees. Les valeurs viennent du prompt courant.",
        }
    if pct >= 48:
        return {
            "score": pct,
            "label": "Moyenne",
            "tone": "info",
            "message": "Schema proche retrouve en base, avec validation par evidence textuelle.",
        }
    return {
        "score": pct,
        "label": "Faible",
        "tone": "info",
        "message": "Correspondance faible: confirmation employee requise.",
    }


def build_report(profile: LearningProfile, result: RetrievalResult, used_llm_fallback: bool = False) -> dict[str, Any]:
    by_category: dict[str, Counter[str]] = defaultdict(Counter)
    for sample in profile.samples:
        category = normalize_ws(sample.general.get("categorie") or sample.general.get("typeDemande") or "Autre")
        for field in sample.fields:
            by_category[category][field.key] += 1
    return {
        "samples": len(profile.samples),
        "retrieval": retrieval_stats(result),
        "fieldCountsByCategory": {
            category: dict(counter.most_common())
            for category, counter in sorted(by_category.items())
        },
        "matchVsFallback": {
            "dbMatch": 1 if result.has_usable_match else 0,
            "localFallback": 0 if result.has_usable_match else 1,
            "llmFallback": 1 if used_llm_fallback else 0,
        },
    }


def generate_response(payload: dict[str, Any]) -> dict[str, Any]:
    source = normalize_ws(payload.get("text") or payload.get("prompt") or "")
    if not source:
        general = payload.get("general") if isinstance(payload.get("general"), dict) else {}
        source = normalize_ws(general.get("aiDescriptionPrompt") or general.get("description") or general.get("titre"))

    raw_records = payload.get("acceptedAutreFeedback")
    records = raw_records if isinstance(raw_records, list) else []
    profile = build_learning_profile(records)
    result = rank_requests(source, profile, top_k=8, threshold=float(payload.get("retrievalThreshold") or MIN_SCHEMA_MATCH_SCORE))

    if result.has_usable_match:
        fields = plan_from_retrieval(source, result, profile)
        evidence_fields, evidence_useful = fallback_fields(source, result.entities)
        if not fields:
            fields, useful_local_plan = evidence_fields, evidence_useful
            used_retrieval = False
        else:
            if evidence_useful:
                fields = _merge_explicit_evidence_fields(fields, evidence_fields, source, result.entities)
            useful_local_plan = True
            used_retrieval = True
    else:
        fields, useful_local_plan = fallback_fields(source, result.entities)
        used_retrieval = False

    fields = [field for field in fields if not should_suppress_field(field, profile, source) or _field_named_explicitly(source, field)]
    fields = _trim_fields(_dedupe_same_values(_dedupe_fields(fields)))
    details = {field.key: field.value for field in fields if normalize_ws(field.value)}
    general_priority, urgency = priority(source)
    manual_schema = bool(result.matches and result.matches[0].sample.manual)
    needs_llm = not used_retrieval and not useful_local_plan

    response = {
        "correctedText": source,
        "general": {
            "titre": build_title(source, fields),
            "description": source,
            "priorite": general_priority,
            "categorie": "Autre",
            "typeDemande": "Autre",
        },
        "details": details,
        "remove_fields": ["ALL"],
        "custom_fields": [field.to_dict() for field in fields],
        "replace_base": True,
        "dynamicFieldConfidence": confidence_payload(result.best_score, used_retrieval, useful_local_plan, manual_schema),
        "skipConfirmationRestriction": False,
        "needsLlmFallback": needs_llm,
        "niveauUrgenceAutre": urgency,
        "_report": build_report(profile, result, used_llm_fallback=False),
    }
    return response


def validate_llm_candidate(source: str, candidate: dict[str, Any]) -> dict[str, Any]:
    entities = extract_entities(source)
    raw_fields = candidate.get("custom_fields") if isinstance(candidate.get("custom_fields"), list) else []
    fields: list[FieldSpec] = []

    for item in raw_fields:
        if not isinstance(item, dict):
            continue
        key = normalize_ws(item.get("key"))
        if not key:
            continue
        label = normalize_ws(item.get("label")) or label_from_key(key)
        field_type = norm(item.get("type") or infer_field_type(key, label, normalize_ws(item.get("value"))))
        if field_type not in {"text", "textarea", "select", "number", "date"}:
            field_type = infer_field_type(key, label, normalize_ws(item.get("value")))
        field = FieldSpec(
            key=key,
            label=label,
            type=field_type,
            required=bool(item.get("required")),
            value=normalize_ws(item.get("value")),
            options=tuple(normalize_ws(option) for option in (item.get("options") if isinstance(item.get("options"), list) else []) if normalize_ws(option)),
            source="llm-fallback",
        )
        value, explicit = extract_value_for_field(source, field, entities, [field])
        if field.type == "select":
            value = _select_value_from_options(value, field.options, source)
        if not value and not field.required:
            continue
        fields.append(replace(field, value=value, explicit=explicit))

    if not fields:
        fallback, useful = fallback_fields(source, entities)
        fields = fallback if useful else []

    details = {field.key: field.value for field in fields if field.value}
    general = candidate.get("general") if isinstance(candidate.get("general"), dict) else {}
    general_priority, _ = priority(source)
    return {
        "correctedText": normalize_ws(candidate.get("correctedText") or source),
        "general": {
            "titre": normalize_ws(general.get("titre")) or build_title(source, fields),
            "description": normalize_ws(general.get("description")) or source,
            "priorite": normalize_ws(general.get("priorite")) or general_priority,
            "categorie": "Autre",
            "typeDemande": "Autre",
        },
        "details": details,
        "remove_fields": ["ALL"],
        "custom_fields": [field.to_dict() for field in _trim_fields(_dedupe_same_values(_dedupe_fields(fields)))],
        "replace_base": True,
        "dynamicFieldConfidence": {
            "score": 42,
            "label": "Faible",
            "tone": "info",
            "message": "Champs LLM post-valides par evidence textuelle.",
        },
        "_model": "llm-fallback:huggingface",
    }
