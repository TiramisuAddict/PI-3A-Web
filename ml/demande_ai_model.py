import json
import math
import re
import sys
import unicodedata
from collections import Counter, defaultdict


def _normalize_ws(text):
    return re.sub(r"\s+", " ", str(text or "")).strip()


def _norm(text):
    value = unicodedata.normalize("NFD", str(text or ""))
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    return _normalize_ws(value).lower()


def _extract_json_value(prompt, label):
    idx = prompt.find(label)
    if idx < 0:
        return None

    segment = prompt[idx + len(label) :].lstrip()
    decoder = json.JSONDecoder()
    try:
        value, _ = decoder.raw_decode(segment)
        return value
    except json.JSONDecodeError:
        return None


def _contains_any(text, keywords):
    return any(k in text for k in keywords)


def _infer_priority(text, allowed):
    allowed_upper = [str(x).upper() for x in (allowed or ["HAUTE", "NORMALE", "BASSE"])]
    normalized = _norm(text)

    if _contains_any(normalized, ["urgent", "urgence", "bloquant", "au plus vite", "immediat"]):
        return "HAUTE" if "HAUTE" in allowed_upper else (allowed_upper[0] if allowed_upper else "NORMALE")

    if _contains_any(normalized, ["pas urgent", "quand possible", "faible"]):
        return "BASSE" if "BASSE" in allowed_upper else (allowed_upper[0] if allowed_upper else "NORMALE")

    return "NORMALE" if "NORMALE" in allowed_upper else (allowed_upper[0] if allowed_upper else "NORMALE")


TYPE_KEYWORDS = {
    "conge": ["conge", "vacance", "absence", "repos", "maladie"],
    "attestation de travail": ["attestation", "travail"],
    "attestation de salaire": ["attestation", "salaire"],
    "certificat de travail": ["certificat", "travail"],
    "mutation": ["mutation", "transfert"],
    "demission": ["demission", "depart"],
    "avance sur salaire": ["avance", "salaire", "paie"],
    "remboursement": ["rembourse", "facture", "frais"],
    "materiel informatique": ["ordinateur", "pc", "laptop", "ecran", "clavier", "souris"],
    "acces systeme": ["acces", "compte", "permission", "erp", "crm", "salesforce"],
    "logiciel": ["logiciel", "licence", "application"],
    "probleme technique": ["bug", "panne", "reseau", "wifi", "imprimante"],
    "teletravail": ["teletravail", "distance", "remote"],
    "heures supplementaires": ["heures supplementaires", "heure sup", "overtime"],
    "formation interne": ["formation interne"],
    "formation externe": ["formation externe", "formation"],
    "certification": ["certification", "certif", "examen"],
}


def _tokenize(text):
    normalized = _norm(text)
    if not normalized:
        return []
    return [t for t in re.split(r"[^a-z0-9]+", normalized) if len(t) >= 2]


def _train_multinomial_nb(samples, label_key):
    labels = []
    label_doc_count = Counter()
    label_token_count = defaultdict(Counter)
    label_total_tokens = Counter()
    vocabulary = set()

    for sample in samples:
        label = str(sample.get(label_key, "") or "").strip()
        text = str(sample.get("text", "") or "")
        if label == "" or text.strip() == "":
            continue

        labels.append(label)
        label_doc_count[label] += 1

        tokens = _tokenize(text)
        for tok in tokens:
            label_token_count[label][tok] += 1
            label_total_tokens[label] += 1
            vocabulary.add(tok)

    if not labels:
        return None

    return {
        "labels": sorted(set(labels)),
        "doc_count": label_doc_count,
        "token_count": label_token_count,
        "total_tokens": label_total_tokens,
        "vocabulary": vocabulary,
        "num_docs": len(labels),
    }


def _predict_multinomial_nb(model, text):
    if model is None:
        return "", 0.0

    tokens = _tokenize(text)
    if not tokens:
        return "", 0.0

    vocab_size = max(1, len(model["vocabulary"]))
    scores = {}

    for label in model["labels"]:
        prior = (model["doc_count"][label] + 1.0) / (model["num_docs"] + len(model["labels"]))
        log_prob = math.log(prior)
        total_for_label = model["total_tokens"][label]
        token_counter = Counter(tokens)

        for token, freq in token_counter.items():
            tok_count = model["token_count"][label][token]
            cond = (tok_count + 1.0) / (total_for_label + vocab_size)
            log_prob += freq * math.log(cond)

        scores[label] = log_prob

    best_label = max(scores, key=scores.get)
    # normalize approximately to confidence using softmax-like ratio.
    max_log = max(scores.values())
    exp_sum = sum(math.exp(v - max_log) for v in scores.values())
    best_conf = math.exp(scores[best_label] - max_log) / exp_sum if exp_sum > 0 else 0.0

    return best_label, float(best_conf)


def _train_ml_models(training_samples):
    if not isinstance(training_samples, list) or len(training_samples) < 20:
        return None, None, None

    category_model = _train_multinomial_nb(training_samples, "categorie")
    type_model = _train_multinomial_nb(training_samples, "typeDemande")
    priority_model = _train_multinomial_nb(training_samples, "priorite")
    return category_model, type_model, priority_model


def _score_type(type_label, normalized_text):
    normalized_type = _norm(type_label)
    score = 0

    if normalized_type in normalized_text:
        score += 6

    for token in normalized_type.split(" "):
        if token and token in normalized_text:
            score += 1

    for candidate, keywords in TYPE_KEYWORDS.items():
        if candidate in normalized_type:
            for keyword in keywords:
                if keyword in normalized_text:
                    score += 3

    return score


def _pick_category_type(raw_text, categories, type_map):
    normalized_text = _norm(raw_text)
    best = (None, None, -1)

    for category in categories:
        available_types = type_map.get(category, [])
        for type_label in available_types:
            s = _score_type(type_label, normalized_text)
            if s > best[2]:
                best = (category, type_label, s)

    if best[0] is not None and best[1] is not None:
        return best

    if categories:
        first_category = categories[0]
        types = type_map.get(first_category, [])
        return first_category, (types[0] if types else "Autre"), 0

    return "Autre", "Autre", 0


def _build_title(type_demande, corrected_text):
    if type_demande:
        return f"Demande - {type_demande}"

    cleaned = _normalize_ws(corrected_text)
    if len(cleaned) > 80:
        cleaned = cleaned[:77] + "..."

    return cleaned or "Nouvelle demande"


def _first_date(text):
    if not text:
        return ""

    exact = re.search(r"\b(\d{4}-\d{2}-\d{2})\b", text)
    if exact:
        return exact.group(1)

    fr = re.search(r"\b(\d{1,2})/(\d{1,2})/(\d{4})\b", text)
    if fr:
        day = int(fr.group(1))
        month = int(fr.group(2))
        year = int(fr.group(3))
        if 1 <= month <= 12 and 1 <= day <= 31:
            return f"{year:04d}-{month:02d}-{day:02d}"

    return ""


def _first_amount(text):
    match = re.search(r"\b(\d{2,6})(?:[\.,]\d{1,2})?\b", text)
    if not match:
        return ""
    return match.group(1)


def _urgency_value(priorite, options):
    if not options:
        return "Normale"

    normalized_options = [str(opt) for opt in options]
    priorities = {
        "HAUTE": ["Tres urgente", "Urgente", "Haute", "Elevee"],
        "NORMALE": ["Normale", "Moyenne", "Standard"],
        "BASSE": ["Faible", "Basse"],
    }

    for candidate in priorities.get(priorite, []):
        for option in normalized_options:
            if _norm(candidate) == _norm(option):
                return option

    for option in normalized_options:
        if _norm(option) == "normale":
            return option

    return normalized_options[0]


def _build_classification_response(prompt):
    raw_text = str(_extract_json_value(prompt, "Texte utilisateur brut: ") or "").strip()
    categories = _extract_json_value(prompt, "Categories autorisees: ")
    type_map = _extract_json_value(prompt, "Types autorises par categorie: ")
    priorities = _extract_json_value(prompt, "Priorites autorisees: ")
    training_samples = _extract_json_value(prompt, "Training samples: ")

    if not isinstance(categories, list):
        categories = ["Autre"]
    if not isinstance(type_map, dict):
        type_map = {str(categories[0]): ["Autre"]} if categories else {"Autre": ["Autre"]}
    if not isinstance(priorities, list):
        priorities = ["HAUTE", "NORMALE", "BASSE"]

    corrected = _normalize_ws(raw_text)

    category_model, type_model, priority_model = _train_ml_models(training_samples)
    ml_category, cat_conf = _predict_multinomial_nb(category_model, corrected)
    ml_type, type_conf = _predict_multinomial_nb(type_model, corrected)
    ml_priority, pri_conf = _predict_multinomial_nb(priority_model, corrected)

    category, type_demande, score = _pick_category_type(corrected, categories, type_map)
    priorite = _infer_priority(corrected, priorities)

    if ml_category and ml_category in categories and cat_conf >= 0.35:
        category = ml_category

    if ml_type and category in type_map and ml_type in type_map.get(category, []) and type_conf >= 0.30:
        type_demande = ml_type
    elif ml_type and type_conf >= 0.38:
        # map predicted type back to category if possible
        for cat_name, types in type_map.items():
            if ml_type in types:
                category = cat_name
                type_demande = ml_type
                break

    if ml_priority and ml_priority in [str(p).upper() for p in priorities] and pri_conf >= 0.35:
        priorite = ml_priority

    available_types = type_map.get(category, [])
    if type_demande not in available_types and available_types:
        type_demande = available_types[0]

    hybrid_conf = 0.45 + (score * 0.03)
    ml_conf = max(cat_conf, type_conf, pri_conf)
    confidence = max(0.35, min(0.97, (0.65 * hybrid_conf) + (0.35 * (0.35 + 0.65 * ml_conf))))

    return {
        "correctedText": corrected,
        "categorie": category,
        "typeDemande": type_demande,
        "priorite": priorite,
        "titre": _build_title(type_demande, corrected),
        "description": corrected,
        "confidence": round(confidence, 2),
    }


def _build_description_response(prompt):
    title = str(_extract_json_value(prompt, "Titre: ") or "").strip()
    type_demande = str(_extract_json_value(prompt, "Type de demande: ") or "").strip()
    categorie = str(_extract_json_value(prompt, "Categorie: ") or "").strip()

    if title == "":
        title = "Nouvelle demande"

    context_chunk = ""
    if type_demande:
        context_chunk += f" de type {type_demande}"
    if categorie:
        context_chunk += f" dans la categorie {categorie}"

    description = (
        f"Bonjour, je souhaite soumettre une demande concernant \"{title}\"{context_chunk}. "
        "Cette demande correspond a un besoin concret que je souhaite traiter de maniere claire et conforme aux procedures internes. "
        "Je souhaite que cette demande soit prise en charge dans les meilleurs delais tout en respectant l organisation du service. "
        "Je reste disponible pour fournir tout complement d information utile et je vous remercie par avance pour votre retour."
    )

    return {"description": _normalize_ws(description)}


def _detail_default_value(key, corrected, priorite, urgency_options, current_title):
    normalized_key = _norm(key)
    date_value = _first_date(corrected)
    amount = _first_amount(corrected)

    if "description" in normalized_key:
        return corrected
    if "besoin" in normalized_key or "titre" in normalized_key:
        return current_title or "Demande personnalisee"
    if "urgence" in normalized_key:
        return _urgency_value(priorite, urgency_options)
    if "date" in normalized_key:
        return date_value
    if "montant" in normalized_key and amount:
        return amount
    if "piece" in normalized_key or "contexte" in normalized_key:
        return corrected[:220]

    return ""


def _build_autre_response(prompt):
    context = _extract_json_value(prompt, "Contexte utilisateur: ")
    allowed_keys = _extract_json_value(prompt, "Les cles details autorisees sont: ")
    required_keys = _extract_json_value(prompt, "Cles details obligatoires: ")
    urgency_options = _extract_json_value(prompt, "- niveauUrgenceAutre: une valeur parmi ")

    if not isinstance(context, dict):
        context = {}
    if not isinstance(allowed_keys, list):
        allowed_keys = ["besoinPersonnalise", "descriptionBesoin", "niveauUrgenceAutre"]
    if not isinstance(required_keys, list):
        required_keys = []
    if not isinstance(urgency_options, list):
        urgency_options = ["Faible", "Normale", "Urgente", "Tres urgente"]

    source_text = _normalize_ws(
        context.get("userPromptAutre")
        or context.get("descriptionGenerale")
        or context.get("titre")
        or ""
    )
    priorite = _infer_priority(source_text, ["HAUTE", "NORMALE", "BASSE"])

    general_title = _normalize_ws(str(context.get("titre") or ""))
    if general_title == "":
        general_title = "Demande personnalisee"

    general_description = source_text
    if general_description == "":
        general_description = (
            f"Bonjour, je souhaite soumettre une demande liee a \"{general_title}\". "
            "Cette demande correspond a un besoin concret a traiter de maniere claire et exploitable."
        )

    details_current = context.get("detailsActuels")
    if not isinstance(details_current, dict):
        details_current = {}

    details = {}
    for key in allowed_keys:
        k = str(key)
        raw_current = details_current.get(k)
        current_value = _normalize_ws(raw_current) if isinstance(raw_current, (str, int, float)) else ""

        if current_value == "":
            current_value = _detail_default_value(k, source_text, priorite, urgency_options, general_title)

        if current_value != "" or k in required_keys:
            details[k] = current_value

    for key in required_keys:
        rk = str(key)
        if rk not in details:
            details[rk] = _detail_default_value(rk, source_text, priorite, urgency_options, general_title)

    return {
        "correctedText": source_text,
        "general": {
            "titre": general_title,
            "description": general_description,
            "priorite": priorite,
        },
        "details": details,
        "remove_fields": [],
        "custom_fields": [],
        "replace_base": False,
    }


def _infer_mode(prompt):
    if "correctedText, categorie, typeDemande, priorite, titre, description, confidence" in prompt:
        return "classification"

    if "une seule cle: description" in _norm(prompt):
        return "description"

    if "correctedText, general, details, remove_fields, custom_fields, replace_base" in prompt:
        return "autre"

    return "unknown"


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

    if prompt == "":
        print(json.dumps({"ok": False, "error": "Missing prompt"}))
        return

    try:
        mode = _infer_mode(prompt)
        if mode == "classification":
            # Enrich prompt with optional training samples payload while keeping compatibility.
            training_samples = request_data.get("trainingSamples", [])
            if training_samples:
                prompt = prompt + "\nTraining samples: " + json.dumps(training_samples, ensure_ascii=False)
            result = _build_classification_response(prompt)
        elif mode == "description":
            result = _build_description_response(prompt)
        elif mode == "autre":
            result = _build_autre_response(prompt)
        else:
            result = {"message": _normalize_ws(prompt)}

        text = json.dumps(result, ensure_ascii=False)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(exc)}))
        return

    print(json.dumps({"ok": True, "text": text}, ensure_ascii=False))


if __name__ == "__main__":
    main()
