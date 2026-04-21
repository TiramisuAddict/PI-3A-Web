import argparse
import json
import random
import subprocess
import sys
from pathlib import Path

import demande_ai_model as model


def _load_json(path):
    p = Path(path)
    if not p.exists():
        return None
    with p.open("r", encoding="utf-8") as f:
        return json.load(f)


def _default_external_sources_file(workspace_root):
    return Path(workspace_root) / "config" / "ai" / "external_training_sources.json"


def _default_autre_feedback_file(workspace_root):
    return Path(workspace_root) / "var" / "ai" / "autre_generation_feedback.jsonl"


def _load_jsonl(path):
    p = Path(path)
    if not p.exists():
        return []
    rows = []
    with p.open("r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            try:
                rows.append(json.loads(line))
            except json.JSONDecodeError:
                continue
    return rows


def _normalize_autre_feedback_samples(rows):
    samples = []
    for row in rows:
        if not isinstance(row, dict):
            continue
        prompt = str(row.get("prompt", "") or "").strip()
        general = row.get("general") if isinstance(row.get("general"), dict) else {}
        details = row.get("details") if isinstance(row.get("details"), dict) else {}
        title = str(general.get("titre", "") or "").strip()
        description = str(general.get("description", "") or "").strip()
        text = " ".join(part for part in [prompt, title, description] if part).strip()
        if not text:
            continue
        samples.append({
            "text": text,
            "categorie": str(general.get("categorie", "") or "Autre").strip() or "Autre",
            "typeDemande": str(general.get("typeDemande", "") or "Autre").strip() or "Autre",
            "priorite": str(general.get("priorite", "") or "NORMALE").strip().upper() or "NORMALE",
            "details": details,
        })
    return samples


def _split_train_test(samples, test_ratio=0.15, seed=42):
    items = list(samples)
    rnd = random.Random(seed)
    rnd.shuffle(items)
    if len(items) < 20:
        return items, []
    test_size = max(10, int(len(items) * test_ratio))
    test = items[:test_size]
    train = items[test_size:]
    return train, test


def _evaluate_simple(models, test_samples):
    if not models or not test_samples:
        return {"category_acc": None, "type_acc": None, "priority_acc": None, "count": len(test_samples)}

    cat_ok = 0
    type_ok = 0
    pri_ok = 0

    categories = sorted({s["categorie"] for s in test_samples if s.get("categorie")})
    priorities = ["HAUTE", "NORMALE", "BASSE"]
    type_map = {}
    for c in categories:
        type_map[c] = sorted({s["typeDemande"] for s in test_samples if s.get("categorie") == c and s.get("typeDemande")})

    for s in test_samples:
        text = s.get("text", "")
        true_cat = s.get("categorie", "")
        true_type = s.get("typeDemande", "")
        true_pri = s.get("priorite", "")

        pred_cat, _ = model._predict_with_ensemble(models, text, categories, "category")
        if pred_cat == true_cat:
            cat_ok += 1

        allowed_types = type_map.get(pred_cat, []) or sorted({t for ts in type_map.values() for t in ts})
        pred_type, _ = model._predict_with_ensemble(models, text, allowed_types, "type")
        if pred_type == true_type:
            type_ok += 1

        pred_pri, _ = model._predict_with_ensemble(models, text, priorities, "priority")
        if pred_pri == true_pri:
            pri_ok += 1

    total = len(test_samples)
    return {
        "category_acc": round(cat_ok / total, 4) if total else None,
        "type_acc": round(type_ok / total, 4) if total else None,
        "priority_acc": round(pri_ok / total, 4) if total else None,
        "count": total,
    }


def _field_benchmark_cases():
    return [
        {
            "family": "transport_formation",
            "prompt": "demande de transport de tunis vers rades pour une formation javascript",
            "expected": {
                "ai_nom_formation": "Javascript",
                "ai_lieu_depart_actuel": "Tunis",
                "ai_lieu_souhaite": "Rades",
                "ai_type_formation": "Formation externe",
            },
        },
        {
            "family": "transport_formation",
            "prompt": "demande de transport de tunis vers rades pour une formation html css",
            "expected": {
                "ai_nom_formation": "Html Css",
                "ai_lieu_depart_actuel": "Tunis",
                "ai_lieu_souhaite": "Rades",
                "ai_type_formation": "Formation externe",
            },
        },
        {
            "family": "transport_formation",
            "prompt": "transport de sfax vers tunis pour une formation ui/ux",
            "expected": {
                "ai_nom_formation": "Ui/ux",
                "ai_lieu_depart_actuel": "Sfax",
                "ai_lieu_souhaite": "Tunis",
                "ai_type_formation": "Formation externe",
            },
        },
        {
            "family": "transport_formation",
            "prompt": "transport de tunis vers nabeul pour une formation c++",
            "expected": {
                "ai_nom_formation": "C++",
                "ai_lieu_depart_actuel": "Tunis",
                "ai_lieu_souhaite": "Nabeul",
                "ai_type_formation": "Formation externe",
            },
        },
        {
            "family": "parking",
            "prompt": "je demande un parking devant l entree principale",
            "expected": {
                "ai_type_stationnement": "Place reservee",
                "ai_zone_souhaitee": "Entree Principale",
            },
        },
        {
            "family": "finance",
            "prompt": "bjr jvux une dmande de remboursment de 85 tnd pour taxi le 14 fevrier 2027 car j ai ete en mission",
            "expected": {
                "ai_type_transport": "Taxi",
                "ai_montant": "85",
                "ai_justification_metier": "j ai ete en mission",
            },
        },
        {
            "family": "finance",
            "prompt": "je souhaite une avance sur salaire de 1200 tnd pour une depense urgente ce mois ci",
            "expected": {
                "ai_montant": "1200",
                "descriptionBesoin": "Je souhaite une avance sur salaire de 1200 tnd pour une depense urgente ce mois ci",
            },
        },
        {
            "family": "access",
            "prompt": "slt je veux un acces vpn urgent pour travailler a distance des demain",
            "expected": {
                "niveauUrgenceAutre": "Tres urgente",
                "general.priorite": "HAUTE",
            },
        },
        {
            "family": "certification",
            "prompt": "je souhaite participer a une certification aws le 20 juin 2027",
            "expected": {
                "ai_type_formation": "Certification",
                "ai_nom_formation": "Aws",
                "dateSouhaiteeAutre": "2027-06-20",
            },
        },
        {
            "family": "maintenance",
            "prompt": "demande de maintenance pour la climatisation du bureau au 3eme etage",
            "expected": {
                "descriptionBesoin": "Demande de maintenance pour la climatisation du bureau au 3eme etage",
            },
        },
        {
            "family": "workplace",
            "prompt": "je veux une chaise ergonomique pour mon poste de travail",
            "expected": {
                "niveauUrgenceAutre": "Faible",
                "general.priorite": "BASSE",
            },
        },
    ]


def _run_autre_prompt(workspace_root, prompt_text):
    script_path = Path(workspace_root) / "ml" / "demande_ai_model.py"
    payload = {
        "prompt": (
            "Tu es un assistant RH/IT qui aide a rediger des demandes internes professionnelles en francais. "
            "Renvoie STRICTEMENT un JSON valide, sans markdown, sans texte avant/apres. "
            "Tu dois renvoyer un objet JSON racine avec les cles: correctedText, general, details, remove_fields, custom_fields, replace_base. "
            "Les cles details autorisees sont: [\"besoinPersonnalise\",\"descriptionBesoin\",\"niveauUrgenceAutre\",\"dateSouhaiteeAutre\",\"pieceOuContexte\"] "
            "Cles details obligatoires: [\"besoinPersonnalise\",\"descriptionBesoin\",\"niveauUrgenceAutre\"] "
            "- niveauUrgenceAutre: une valeur parmi [\"Faible\",\"Normale\",\"Urgente\",\"Tres urgente\"] "
            "Contexte utilisateur: "
            + json.dumps(
                {
                    "categorie": "Autre",
                    "typeDemande": "Autre",
                    "titre": "",
                    "descriptionGenerale": "",
                    "priorite": "",
                    "userPromptAutre": prompt_text,
                    "detailsActuels": {},
                },
                ensure_ascii=False,
            )
        ),
        "trainingSamples": [],
    }

    result = subprocess.run(
        [sys.executable, str(script_path)],
        input=json.dumps(payload, ensure_ascii=False),
        text=True,
        capture_output=True,
        cwd=str(workspace_root),
        check=True,
    )
    outer = json.loads(result.stdout)
    inner = json.loads(outer["text"])
    return inner


def _flatten_autre_result(result):
    flattened = {}
    for key, value in (result.get("details") or {}).items():
        flattened[str(key)] = str(value)
    general = result.get("general") or {}
    for key, value in general.items():
        flattened[f"general.{key}"] = str(value)
    for field in result.get("custom_fields") or []:
        if not isinstance(field, dict) or not field.get("key"):
            continue
        flattened[str(field["key"])] = str(field.get("value", ""))
    return flattened


def _evaluate_field_benchmark(workspace_root):
    rows = []
    family_totals = {}
    total_checks = 0
    total_hits = 0

    for case in _field_benchmark_cases():
        result = _run_autre_prompt(workspace_root, case["prompt"])
        flattened = _flatten_autre_result(result)
        family = case["family"]
        family_totals.setdefault(family, {"checks": 0, "hits": 0})

        for key, expected in case["expected"].items():
            actual = flattened.get(key, "")
            ok = actual == expected
            total_checks += 1
            total_hits += int(ok)
            family_totals[family]["checks"] += 1
            family_totals[family]["hits"] += int(ok)
            rows.append(
                {
                    "family": family,
                    "prompt": case["prompt"],
                    "field": key,
                    "expected": expected,
                    "actual": actual,
                    "ok": ok,
                }
            )

    families = {}
    for family, totals in family_totals.items():
        checks = totals["checks"]
        hits = totals["hits"]
        families[family] = {
            "checks": checks,
            "hits": hits,
            "accuracy": round(hits / checks, 4) if checks else None,
        }

    return {
        "checks": total_checks,
        "hits": total_hits,
        "accuracy": round(total_hits / total_checks, 4) if total_checks else None,
        "families": families,
        "rows": rows,
    }


def main():
    parser = argparse.ArgumentParser(description="Train demande ML bundle from DB + curated external samples")
    parser.add_argument("--db-samples", default="var/ai/classification_training_samples.json", help="Path to DB samples JSON")
    parser.add_argument("--external-sources", default=None, help="Path to external sources JSON")
    parser.add_argument("--autre-feedback", default=None, help="Path to accepted Autre feedback JSONL")
    parser.add_argument("--output-sources", default="var/ai/curated_external_training_sources.json", help="Path to write curated external sources")
    parser.add_argument("--report", default="var/ai/demande_training_report.json", help="Path to write training report")
    parser.add_argument("--field-benchmark-report", default="var/ai/demande_field_benchmark_report.json", help="Path to write field extraction benchmark report")
    args = parser.parse_args()

    workspace_root = Path(__file__).resolve().parents[1]

    db_data = _load_json(args.db_samples) or []
    db_norm = model._normalize_training_samples(db_data)

    external_path = args.external_sources or str(_default_external_sources_file(workspace_root))
    external_data = _load_json(external_path)
    if external_data is None:
        external_data = []

    if isinstance(external_data, list):
        req = {"externalTrainingSources": external_data}
        external_norm = model._extract_external_training_samples(req)
    else:
        external_norm = []

    autre_feedback_path = args.autre_feedback or str(_default_autre_feedback_file(workspace_root))
    autre_feedback_rows = _load_jsonl(autre_feedback_path)
    autre_feedback_norm = _normalize_autre_feedback_samples(autre_feedback_rows)

    blended = model._blend_training_sources(db_norm + autre_feedback_norm, external_norm)
    augmented = model._augment_training_samples(blended)

    categories = sorted({s.get("categorie", "") for s in augmented if s.get("categorie")})
    if not categories:
        categories = ["Autre"]

    type_map = {}
    for c in categories:
        type_map[c] = sorted({s.get("typeDemande", "") for s in augmented if s.get("categorie") == c and s.get("typeDemande")}) or ["Autre"]

    priorities = ["HAUTE", "NORMALE", "BASSE"]
    aligned = model._align_training_samples(augmented, categories, type_map, priorities)

    train_set, test_set = _split_train_test(aligned)
    models = model._train_all_models(train_set)
    eval_report = _evaluate_simple(models, test_set)
    field_benchmark_report = _evaluate_field_benchmark(workspace_root)

    curated_sources_out = []
    if external_norm:
        curated_sources_out = [{
            "source": "c4_multilingual_curated",
            "samples": [{
                "text": s.get("text", ""),
                "categorie": s.get("categorie", ""),
                "typeDemande": s.get("typeDemande", ""),
                "priorite": s.get("priorite", "NORMALE"),
            } for s in external_norm[:800]]
        }]

    report = {
        "db_samples": len(db_norm),
        "autre_feedback_samples": len(autre_feedback_norm),
        "external_samples_filtered": len(external_norm),
        "blended_samples": len(blended),
        "augmented_samples": len(augmented),
        "aligned_samples": len(aligned),
        "train_samples": len(train_set),
        "test_samples": len(test_set),
        "evaluation": eval_report,
        "field_benchmark": {
            "accuracy": field_benchmark_report.get("accuracy"),
            "checks": field_benchmark_report.get("checks"),
            "hits": field_benchmark_report.get("hits"),
            "families": field_benchmark_report.get("families", {}),
        },
    }

    output_sources_path = Path(args.output_sources)
    output_sources_path.parent.mkdir(parents=True, exist_ok=True)
    output_sources_path.write_text(json.dumps(curated_sources_out, ensure_ascii=False, indent=2), encoding="utf-8")

    report_path = Path(args.report)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")

    field_benchmark_path = Path(args.field_benchmark_report)
    field_benchmark_path.parent.mkdir(parents=True, exist_ok=True)
    field_benchmark_path.write_text(json.dumps(field_benchmark_report, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps({"ok": True, "report": report, "output_sources": str(output_sources_path), "report_file": str(report_path), "field_benchmark_report_file": str(field_benchmark_path)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
