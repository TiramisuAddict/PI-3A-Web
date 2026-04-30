import json
import math
import re
import sys
import unicodedata
from collections import Counter, defaultdict


def _norm(text):
    value = unicodedata.normalize("NFD", str(text or ""))
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    value = value.lower().strip()
    value = re.sub(r"\s+", " ", value)
    return value


def _is_low_quality(value, field_type="text"):
    text = _norm(value)
    if text == "":
        return True

    if field_type == "number" or re.match(r"^\d+(?:[\.,]\d+)?$", text):
        return False

    if field_type == "date" or re.match(r"^\d{4}-\d{2}-\d{2}$", text):
        return False

    if field_type == "select":
        return False

    if len(text) < 4:
        return True

    if re.match(r"^(test|aaaa+|bbbb+|cccc+|xxxxx+|demo|tmp|qsdf+|azerty+)$", text):
        return True

    if re.match(r"^(a|b|c|d|e|x|z|1|0|\?|\.)\1{2,}$", text):
        return True

    tokens = [t for t in text.split(" ") if t]
    if len(tokens) >= 2 and len(set(tokens)) <= 1:
        return True

    return False


def _tokenize(text):
    base = _norm(text)
    if base == "":
        return []
    return [tok for tok in re.split(r"[^a-z0-9]+", base) if len(tok) >= 2]


def _status_to_risk_label(status):
    s = _norm(status)
    if s in {"rejetee", "annulee", "annule", "rejetee "}:
        return "eleve"
    if s in {"en attente", "reconsideration"}:
        return "moyen"
    if s in {"resolue", "resolu", "fermee", "en cours", "nouvelle"}:
        return "faible"
    return "moyen"


def _train_nb(samples):
    labels = []
    doc_count = Counter()
    token_count = defaultdict(Counter)
    total_tokens = Counter()
    vocabulary = set()

    for sample in samples:
        if not isinstance(sample, dict):
            continue

        text = str(sample.get("text", "") or "")
        if text.strip() == "":
            continue

        label = _status_to_risk_label(str(sample.get("status", "") or ""))
        labels.append(label)
        doc_count[label] += 1

        for token in _tokenize(text):
            token_count[label][token] += 1
            total_tokens[label] += 1
            vocabulary.add(token)

    if not labels:
        return None

    return {
        "labels": sorted(set(labels)),
        "doc_count": doc_count,
        "token_count": token_count,
        "total_tokens": total_tokens,
        "vocabulary": vocabulary,
        "num_docs": len(labels),
    }


def _predict_nb(model, text):
    if model is None:
        return "", 0.0

    tokens = _tokenize(text)
    if not tokens:
        return "", 0.0

    scores = {}
    vocab_size = max(1, len(model["vocabulary"]))
    token_counter = Counter(tokens)

    for label in model["labels"]:
        prior = (model["doc_count"][label] + 1.0) / (model["num_docs"] + len(model["labels"]))
        log_prob = math.log(prior)
        total_for_label = model["total_tokens"][label]

        for token, freq in token_counter.items():
            count = model["token_count"][label][token]
            cond = (count + 1.0) / (total_for_label + vocab_size)
            log_prob += freq * math.log(cond)

        scores[label] = log_prob

    best_label = max(scores, key=scores.get)
    max_log = max(scores.values())
    exp_sum = sum(math.exp(v - max_log) for v in scores.values())
    conf = math.exp(scores[best_label] - max_log) / exp_sum if exp_sum > 0 else 0.0
    return best_label, float(conf)


def _compute_signals(demande, details, field_definitions):
    title = str(demande.get("titre", "") or "")
    description = str(demande.get("description", "") or "")
    priorite = _norm(demande.get("priorite", ""))
    type_demande = _norm(demande.get("typeDemande", ""))

    required_defs = [f for f in field_definitions if isinstance(f, dict) and bool(f.get("required"))]
    missing_required = 0
    weak_required = 0

    for field in required_defs:
        key = str(field.get("key", "") or "")
        field_type = str(field.get("type", "text") or "text")
        value = details.get(key, "") if isinstance(details, dict) else ""
        value_text = str(value) if isinstance(value, (str, int, float)) else ""

        if value_text.strip() == "":
            missing_required += 1
        elif _is_low_quality(value_text, field_type):
            weak_required += 1

    spam_score = 0
    if _is_low_quality(title, "text"):
        spam_score += 30
    if _is_low_quality(description, "textarea"):
        spam_score += 35

    spam_score += min(30, weak_required * 12)
    spam_score += min(20, missing_required * 6)

    if isinstance(details, dict):
        for value in details.values():
            if isinstance(value, (str, int, float)) and _is_low_quality(str(value), "text"):
                spam_score += 4

    spam_score = max(0, min(100, spam_score))

    confidence = 0.78
    if missing_required > 0:
        confidence -= min(0.38, 0.10 + (missing_required * 0.08))
    if weak_required > 0:
        confidence -= min(0.30, weak_required * 0.10)

    if priorite == "haute" and spam_score < 40:
        confidence += 0.03

    if type_demande == "autre" and len(_norm(description)) < 18:
        confidence -= 0.10

    confidence = max(0.08, min(0.98, confidence))

    if spam_score >= 70:
        risk = "eleve"
        note = "Contenu probablement non fiable ou tres faible."
    elif spam_score >= 40:
        risk = "moyen"
        note = "Plusieurs signaux de qualite faible detectes."
    else:
        risk = "faible"
        note = "Le contenu semble exploitable avec un risque limite."

    if missing_required > 0:
        note += f" Champs obligatoires manquants: {missing_required}."
    if weak_required > 0:
        note += f" Champs obligatoires faibles: {weak_required}."

    return {
        "confidence": round(confidence, 2),
        "spamScore": int(spam_score),
        "risk": risk,
        "note": note,
    }


def _blend_with_learning(signals, demande, details, training_samples):
    if not isinstance(signals, dict):
        return signals

    if not isinstance(training_samples, list) or len(training_samples) < 30:
        return signals

    model = _train_nb(training_samples)
    if model is None:
        return signals

    detail_text = ""
    if isinstance(details, dict):
        detail_text = " ".join(str(v) for v in details.values() if isinstance(v, (str, int, float)))

    text = f"{demande.get('titre', '')} {demande.get('description', '')} {detail_text}".strip()
    predicted_risk, risk_conf = _predict_nb(model, text)
    if predicted_risk == "":
        return signals

    confidence = float(signals.get("confidence", 0.5))
    spam_score = int(signals.get("spamScore", 50))
    note = str(signals.get("note", "") or "")

    if predicted_risk == "eleve":
        spam_score = max(spam_score, int(58 + (risk_conf * 34)))
        confidence = min(confidence, max(0.22, 0.62 - (risk_conf * 0.36)))
    elif predicted_risk == "moyen":
        spam_score = max(spam_score, int(38 + (risk_conf * 22)))
        confidence = min(confidence, max(0.35, 0.78 - (risk_conf * 0.22)))
    else:
        spam_score = max(0, spam_score - int(10 * risk_conf))
        confidence = min(0.98, confidence + (0.08 * risk_conf))

    if note:
        note = note + " "
    note += f"Signal appris: risque {predicted_risk} ({round(risk_conf, 2)})."

    if spam_score >= 70:
        risk = "eleve"
    elif spam_score >= 40:
        risk = "moyen"
    else:
        risk = "faible"

    return {
        "confidence": round(max(0.08, min(0.98, confidence)), 2),
        "spamScore": int(max(0, min(100, spam_score))),
        "risk": risk,
        "note": note,
    }


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

    demande = request_data.get("demande", {})
    details = request_data.get("details", {})
    field_definitions = request_data.get("fieldDefinitions", [])
    training_samples = request_data.get("trainingSamples", [])

    try:
        signals = _compute_signals(demande, details, field_definitions)
        signals = _blend_with_learning(signals, demande, details, training_samples)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(exc)}))
        return

    print(
        json.dumps(
            {
                "ok": True,
                "signals": signals,
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
