from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from difflib import SequenceMatcher
import math
import re
from typing import Iterable

from extractors import ExtractedEntities, contains_term, extract_entities, has_any_word, norm, normalize_ws, tokenize
from learning_store import FieldSpec, LearningProfile, TrainingSample, build_learning_profile


MIN_SCHEMA_MATCH_SCORE = 0.52


@dataclass(frozen=True)
class RetrievalMatch:
    score: float
    lexical_score: float
    schema_score: float
    sample: TrainingSample
    fields: tuple[FieldSpec, ...]


@dataclass(frozen=True)
class RetrievalResult:
    query: str
    entities: ExtractedEntities
    matches: tuple[RetrievalMatch, ...]
    best_score: float
    threshold: float = MIN_SCHEMA_MATCH_SCORE

    @property
    def has_usable_match(self) -> bool:
        return bool(self.matches) and self.best_score >= self.threshold


def field_role(field: FieldSpec) -> str:
    haystack = norm(f"{field.key} {field.label}")
    if field.type == "date" or any(token in haystack for token in ["date", "jour", "echeance", "deadline"]):
        return "date"
    if field.type == "number" or any(token in haystack for token in ["montant", "prix", "cout", "quantite", "nombre", "total"]):
        return "number"
    if any(token in haystack for token in ["depart", "origine"]):
        return "route_from"
    if any(token in haystack for token in ["destination", "arrivee"]) or ("lieu" in haystack and "souhaite" in haystack):
        return "route_to"
    if any(token in haystack for token in ["lieu", "zone", "adresse", "localisation"]):
        return "location"
    if any(token in haystack for token in ["contrainte", "restriction", "preference", "obligation"]):
        return "constraint"
    if any(token in haystack for token in ["transport", "moyen", "vehicule", "trajet"]):
        return "transport_mode"
    if any(token in haystack for token in ["formation", "certification", "cours"]):
        return "training"
    if any(token in haystack for token in ["attestation"]):
        return "attestation"
    if any(token in haystack for token in ["organisme", "destinataire", "beneficiaire", "banque"]):
        return "organization"
    if any(token in haystack for token in ["materiel", "equipement", "objet", "article", "produit", "accessoire", "outil"]):
        return "object"
    if any(token in haystack for token in ["specification", "modele", "reference", "taille", "dimension", "version"]):
        return "specification"
    if any(token in haystack for token in ["type", "nature", "categorie", "frais", "depense"]):
        return "category"
    if any(token in haystack for token in ["description", "justification", "motif", "raison", "contexte", "usage", "detail"]):
        return "reason"
    if any(token in haystack for token in ["salle", "room"]):
        return "room"
    if any(token in haystack for token in ["horaire", "heure", "creneau", "shift", "poste"]):
        return "time"
    if any(token in haystack for token in ["periode", "duree", "semaine", "mois"]):
        return "period"
    return "generic"


def _source_supports_role(source: str, role: str, entities: ExtractedEntities) -> bool:
    if role == "date":
        return bool(entities.date_start)
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
        return bool(entities.training_name)
    if role == "attestation":
        return bool(entities.attestation_type)
    if role == "organization":
        return bool(entities.organization)
    if role == "object":
        return bool(entities.requested_object)
    if role == "specification":
        return bool(entities.specification)
    if role == "category":
        return bool(entities.expense_type or entities.leave_type or entities.transport_mode or has_any_word(source, ["parking", "stationnement", "shift", "horaire"]))
    if role == "reason":
        return bool(entities.reason)
    if role == "room":
        return has_any_word(source, ["salle", "room", "reservation"])
    if role == "time":
        return bool(entities.time_range or entities.schedule_target)
    if role == "period":
        return bool(entities.date_start or entities.date_end or has_any_word(source, ["semaine", "mois", "jour", "jours"]))
    return False


def _schema_support_score(source: str, fields: Iterable[FieldSpec], entities: ExtractedEntities) -> float:
    roles = [field_role(field) for field in fields]
    roles = [role for role in roles if role != "generic"]
    if not roles:
        return 0.0
    supported = sum(1 for role in roles if _source_supports_role(source, role, entities))
    return supported / max(1, len(roles))


def _field_schema_text(fields: Iterable[FieldSpec]) -> str:
    return " ".join(f"{field.key} {field.label}" for field in fields)


def _lexical_similarity(query: str, sample: TrainingSample, fields: Iterable[FieldSpec]) -> float:
    query_tokens = set(tokenize(query))
    sample_text = normalize_ws(" ".join([sample.prompt, str(sample.general.get("titre", "")), str(sample.general.get("description", ""))]))
    sample_tokens = set(tokenize(sample_text))
    if not query_tokens or not sample_tokens:
        return 0.0

    overlap = query_tokens & sample_tokens
    union = query_tokens | sample_tokens
    jaccard = len(overlap) / max(1, len(union))
    query_coverage = len(overlap) / max(1, len(query_tokens))
    sample_coverage = len(overlap) / max(1, len(sample_tokens))
    sequence = SequenceMatcher(None, norm(query), norm(sample_text)).ratio()

    schema_tokens = set(tokenize(_field_schema_text(fields), include_bigrams=False))
    schema_bridge = len(query_tokens & schema_tokens) / max(1, min(len(query_tokens), len(schema_tokens))) if schema_tokens else 0.0

    return max(0.0, min(1.0, jaccard * 0.36 + query_coverage * 0.30 + sample_coverage * 0.12 + sequence * 0.16 + schema_bridge * 0.06))


def _idf_weights(samples: Iterable[TrainingSample]) -> dict[str, float]:
    sample_list = list(samples)
    doc_count = max(1, len(sample_list))
    df: dict[str, int] = {}
    for sample in sample_list:
        seen = set(tokenize(sample.prompt))
        for token in seen:
            df[token] = df.get(token, 0) + 1
    return {token: math.log((doc_count + 1) / (count + 1)) + 1.0 for token, count in df.items()}


def _tfidf_overlap(query: str, sample: TrainingSample, idf: dict[str, float]) -> float:
    query_tokens = set(tokenize(query))
    sample_tokens = set(tokenize(sample.prompt))
    if not query_tokens or not sample_tokens:
        return 0.0
    overlap = query_tokens & sample_tokens
    numerator = sum(idf.get(token, 1.0) for token in overlap)
    denominator = sum(idf.get(token, 1.0) for token in query_tokens | sample_tokens)
    return numerator / max(1e-9, denominator)


def rank_requests(
    query: str,
    records: Iterable[dict] | Iterable[TrainingSample] | LearningProfile,
    *,
    top_k: int = 8,
    threshold: float = MIN_SCHEMA_MATCH_SCORE,
) -> RetrievalResult:
    source = normalize_ws(query)
    entities = extract_entities(source)
    if isinstance(records, LearningProfile):
        profile = records
    else:
        profile = build_learning_profile(records)

    idf = _idf_weights(profile.samples)
    matches: list[RetrievalMatch] = []
    newest = max((sample.created_at for sample in profile.samples), default=None)
    oldest = min((sample.created_at for sample in profile.samples), default=None)

    for sample in profile.samples:
        if not sample.fields:
            continue
        lexical = _lexical_similarity(source, sample, sample.fields)
        tfidf = _tfidf_overlap(source, sample, idf)
        schema = _schema_support_score(source, sample.fields, entities)
        score = lexical * 0.48 + tfidf * 0.16 + schema * 0.46

        if norm(source) == norm(sample.prompt):
            score = max(score, 0.99)

        if sample.manual:
            score += 0.04
        if sample.learning_source == "database":
            score += 0.02
        if newest and oldest and newest > oldest and sample.created_at != datetime.min:
            score += max(0.0, min(1.0, (sample.created_at - oldest).total_seconds() / (newest - oldest).total_seconds())) * 0.02

        score = max(0.0, min(1.0, score))
        if score < 0.18:
            continue
        matches.append(
            RetrievalMatch(
                score=score,
                lexical_score=max(lexical, tfidf),
                schema_score=schema,
                sample=sample,
                fields=sample.fields,
            )
        )

    matches.sort(key=lambda item: (item.score, item.sample.manual, item.sample.created_at), reverse=True)
    selected = tuple(matches[:top_k])
    return RetrievalResult(
        query=source,
        entities=entities,
        matches=selected,
        best_score=selected[0].score if selected else 0.0,
        threshold=threshold,
    )


def retrieval_stats(result: RetrievalResult) -> dict[str, object]:
    return {
        "topScore": round(result.best_score, 4),
        "threshold": result.threshold,
        "usable": result.has_usable_match,
        "matches": [
            {
                "score": round(match.score, 4),
                "lexical": round(match.lexical_score, 4),
                "schema": round(match.schema_score, 4),
                "prompt": match.sample.prompt[:160],
                "fields": [field.key for field in match.fields],
            }
            for match in result.matches
        ],
    }
