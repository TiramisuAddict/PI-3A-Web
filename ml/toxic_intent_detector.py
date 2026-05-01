#!/usr/bin/env python3
"""Train and run a lightweight toxic-intent detector for comments.

The app calls this script in `predict` mode with JSON on stdin and expects JSON
on stdout. Train mode accepts Jigsaw/Civil Comments-style CSV files and creates
a local scikit-learn model that can catch harmful meaning beyond exact bad
words.
"""

from __future__ import annotations

import argparse
import html
import json
import os
import re
import sys
from pathlib import Path
from typing import Any


DEFAULT_MODEL_PATH = Path(__file__).resolve().parents[1] / "var" / "ml" / "toxic_intent_model.joblib"
JIGSAW_LABELS = ("toxic", "severe_toxic", "obscene", "threat", "insult", "identity_hate")

HARMFUL_PATTERNS = {
    "threat": (
        r"\b(i\s+will|i'm\s+going\s+to|im\s+going\s+to|you\s+deserve\s+to)\s+(hurt|destroy|kill|beat|ruin)\b",
        r"\b(wait\s+until|watch\s+out|you\s+are\s+finished)\b",
    ),
    "harassment": (
        r"\b(no\s+one\s+(likes|wants|needs)\s+you)\b",
        r"\b(everyone\s+(hates|laughs\s+at)\s+you)\b",
        r"\b(you\s+should\s+(leave|disappear|quit))\b",
        r"\b(go\s+away\s+forever)\b",
    ),
    "insult": (
        r"\b(can(?:not|'t)\s+think\s+of\s+an\s+insult\s+good\s+enough\s+for\s+you\s+to\s+understand)\b",
        r"\b(not\s+(smart|clever|bright)\s+enough\s+to\s+understand)\b",
        r"\b(too\s+(stupid|slow|ignorant)\s+to\s+understand)\b",
        r"\b(you\s+are\s+(worthless|useless|pathetic|a\s+joke))\b",
        r"\b(your\s+(work|idea|face|life)\s+is\s+(worthless|useless|a\s+joke))\b",
        r"\b(nobody\s+cares\s+about\s+you)\b",
    ),
    "identity_hate": (
        r"\b(people\s+like\s+you\s+are\s+(not\s+welcome|the\s+problem))\b",
        r"\b(your\s+kind\s+(should|must)\s+(leave|go\s+away))\b",
    ),
}


def clean_comment_text(text: str) -> str:
    text = html.unescape(str(text))
    text = re.sub(r"https?://\S+|www\.\S+", " URL ", text, flags=re.IGNORECASE)
    text = re.sub(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b", " EMAIL ", text)
    text = re.sub(r"\b\d{1,3}(?:\.\d{1,3}){3}\b", " IPADDRESS ", text)
    text = re.sub(r"@\w+", " USER ", text)
    text = re.sub(r"#(\w+)", r"\1", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def normalize_text(text: str) -> str:
    text = clean_comment_text(text)
    text = text.lower()
    text = text.translate(str.maketrans({"@": "a", "0": "o", "1": "i", "3": "e", "$": "s", "5": "s", "7": "t", "!": "i"}))
    text = re.sub(r"(.)\1{2,}", r"\1\1", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def heuristic_predict(text: str) -> dict[str, Any]:
    normalized = normalize_text(text)
    labels: dict[str, float] = {}

    for label, patterns in HARMFUL_PATTERNS.items():
        hits = sum(1 for pattern in patterns if re.search(pattern, normalized, flags=re.IGNORECASE))
        if hits:
            labels[label] = min(0.97, 0.62 + (hits * 0.15))

    hostile_second_person = len(re.findall(r"\byou\b", normalized)) >= 1
    hostile_verbs = re.search(r"\b(leave|disappear|fail|lose|cry|suffer|regret)\b", normalized) is not None
    belittling = re.search(r"\b(always|never|nobody|everyone|worthless|useless|pathetic)\b", normalized) is not None
    if hostile_second_person and hostile_verbs and belittling:
        labels["harassment"] = max(labels.get("harassment", 0.0), 0.7)

    score = max(labels.values(), default=0.0)

    return {
        "available": True,
        "engine": "heuristic-fallback",
        "score": round(float(score), 4),
        "is_toxic": score >= 0.72,
        "needs_review": score >= 0.55,
        "labels": {key: round(float(value), 4) for key, value in sorted(labels.items(), key=lambda item: item[1], reverse=True)},
    }


def load_model(model_path: Path) -> Any | None:
    if not model_path.exists():
        return None

    try:
        import joblib  # type: ignore
    except Exception:
        return None

    return joblib.load(model_path)


def model_predict(text: str, model_path: Path) -> dict[str, Any] | None:
    model = load_model(model_path)
    if model is None:
        return None

    if hasattr(model, "predict_proba"):
        probability = float(model.predict_proba([clean_comment_text(text)])[0][1])
    else:
        prediction = model.predict([clean_comment_text(text)])[0]
        probability = float(prediction)

    labels = {"toxic_intent": probability} if probability > 0 else {}

    return {
        "available": True,
        "engine": "sklearn-tfidf-logreg",
        "score": round(probability, 4),
        "is_toxic": probability >= 0.72,
        "needs_review": probability >= 0.55,
        "labels": labels,
    }


def merge_predictions(primary: dict[str, Any], secondary: dict[str, Any]) -> dict[str, Any]:
    primary_labels = dict(primary.get("labels", {}))
    secondary_labels = dict(secondary.get("labels", {}))

    labels: dict[str, float] = {}
    for label in set(primary_labels) | set(secondary_labels):
        labels[label] = max(float(primary_labels.get(label, 0.0)), float(secondary_labels.get(label, 0.0)))

    score = max(float(primary.get("score", 0.0)), float(secondary.get("score", 0.0)), max(labels.values(), default=0.0))
    labels = {key: round(float(value), 4) for key, value in sorted(labels.items(), key=lambda item: item[1], reverse=True)}

    return {
        "available": True,
        "engine": f"{primary.get('engine', 'primary')}+{secondary.get('engine', 'secondary')}",
        "score": round(score, 4),
        "is_toxic": score >= 0.72,
        "needs_review": score >= 0.55,
        "labels": labels,
    }


def read_predict_text(args: argparse.Namespace) -> str:
    if args.text:
        return args.text

    raw_input = sys.stdin.read().strip()
    if not raw_input:
        return ""

    try:
        payload = json.loads(raw_input)
    except json.JSONDecodeError:
        return raw_input

    return str(payload.get("text", ""))


def predict(args: argparse.Namespace) -> int:
    text = read_predict_text(args)
    if not text.strip():
        print(json.dumps({"available": True, "engine": "empty", "score": 0.0, "is_toxic": False, "needs_review": False, "labels": {}}))
        return 0

    model_path = Path(args.model_path) if args.model_path else DEFAULT_MODEL_PATH
    model_result = model_predict(text, model_path)
    heuristic_result = heuristic_predict(text)
    result = merge_predictions(model_result, heuristic_result) if model_result else heuristic_result
    print(json.dumps(result, ensure_ascii=True))
    return 0


def parse_positive_values(raw_values: str) -> set[str]:
    return {value.strip().lower() for value in raw_values.split(",") if value.strip() != ""}


def build_target_from_column(data: Any, label_column: str, positive_threshold: float, positive_values: str) -> Any:
    label_values = data[label_column]
    parsed_positive_values = parse_positive_values(positive_values)
    if parsed_positive_values:
        return label_values.astype(str).str.strip().str.lower().isin(parsed_positive_values)

    return label_values.astype(float) >= positive_threshold


def load_training_frame(args: argparse.Namespace) -> Any:
    import pandas as pd  # type: ignore

    frames = []
    dataset_paths = [args.dataset] + list(args.augment_dataset or [])

    for raw_path in dataset_paths:
        dataset_path = Path(raw_path)
        data = pd.read_csv(dataset_path)

        text_column = args.text_column
        if text_column not in data.columns:
            candidates = ("comment_text", "text", "tweet", "comment", "content", "commentaire")
            text_column = next((column for column in candidates if column in data.columns), text_column)
        if text_column not in data.columns:
            raise ValueError(f"Text column '{args.text_column}' was not found in {dataset_path}")

        label_column = args.label_column
        if label_column and label_column not in data.columns:
            label_candidates = ("label", "target", "toxic", "class", "is_toxic")
            label_column = next((column for column in label_candidates if column in data.columns), label_column)

        if label_column and label_column in data.columns:
            target = build_target_from_column(data, label_column, args.positive_threshold, args.positive_values)
        else:
            available_labels = [label for label in JIGSAW_LABELS if label in data.columns]
            if not available_labels:
                raise ValueError("No label column found. Pass --label-column or use a Jigsaw-style CSV.")
            target = data[available_labels].astype(float).max(axis=1) >= args.positive_threshold

        frame = pd.DataFrame({"text": data[text_column].fillna("").astype(str), "target": target.astype(int)})
        frames.append(frame)

    combined = pd.concat(frames, ignore_index=True)
    combined["text"] = combined["text"].map(clean_comment_text)
    combined = combined[combined["text"].str.strip() != ""]
    return combined


def export_pipeline_for_php(pipeline: Any, output_path: Path) -> Path:
    vectorizer = pipeline.named_steps["tfidf"]
    classifier = pipeline.named_steps["clf"]

    feature_names = vectorizer.get_feature_names_out()
    idf_values = vectorizer.idf_
    coef_values = classifier.coef_[0]

    export_path = output_path.with_suffix(".json")
    features = {
        feature: [round(float(idf), 8), round(float(coef), 8)]
        for feature, idf, coef in zip(feature_names, idf_values, coef_values)
    }

    export_path.write_text(
        json.dumps(
            {
                "engine": "sklearn-tfidf-logreg-json-v1",
                "intercept": round(float(classifier.intercept_[0]), 8),
                "features": features,
            },
            ensure_ascii=True,
        ),
        encoding="utf-8",
    )

    return export_path


def train(args: argparse.Namespace) -> int:
    try:
        import joblib  # type: ignore
        import pandas as pd  # type: ignore
        from sklearn.feature_extraction.text import TfidfVectorizer  # type: ignore
        from sklearn.linear_model import LogisticRegression  # type: ignore
        from sklearn.metrics import classification_report  # type: ignore
        from sklearn.model_selection import train_test_split  # type: ignore
        from sklearn.pipeline import Pipeline  # type: ignore
    except Exception as exc:
        print(
            json.dumps(
                {
                    "success": False,
                    "message": "Training needs pandas, scikit-learn, and joblib installed.",
                    "error": str(exc),
                }
            ),
            file=sys.stderr,
        )
        return 2

    frame = load_training_frame(args)

    train_text, test_text, train_target, test_target = train_test_split(
        frame["text"],
        frame["target"],
        test_size=args.test_size,
        random_state=42,
        stratify=frame["target"] if frame["target"].nunique() > 1 else None,
    )

    pipeline = Pipeline(
        steps=[
            ("tfidf", TfidfVectorizer(ngram_range=(1, 2), min_df=2, max_features=args.max_features, strip_accents="unicode")),
            ("clf", LogisticRegression(max_iter=1000, class_weight="balanced")),
        ]
    )
    pipeline.fit(train_text, train_target)

    predictions = pipeline.predict(test_text)
    report = classification_report(test_target, predictions, output_dict=True, zero_division=0)

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(pipeline, output_path)
    export_path = export_pipeline_for_php(pipeline, output_path)

    print(
        json.dumps(
            {
                "success": True,
                "model_path": str(output_path),
                "php_model_path": str(export_path),
                "rows": int(len(frame)),
                "positive_rows": int(frame["target"].sum()),
                "accuracy": round(float(report["accuracy"]), 4),
                "toxic_f1": round(float(report.get("1", {}).get("f1-score", 0.0)), 4),
            },
            ensure_ascii=True,
        )
    )
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Toxic intent detector")
    subparsers = parser.add_subparsers(dest="command", required=True)

    predict_parser = subparsers.add_parser("predict", help="Predict toxicity for one comment")
    predict_parser.add_argument("--text", default="", help="Comment text. If omitted, stdin is used.")
    predict_parser.add_argument("--json", action="store_true", help="Accepted for app integration compatibility.")
    predict_parser.add_argument("--model-path", default=os.getenv("TOXICITY_MODEL_PATH", str(DEFAULT_MODEL_PATH)))
    predict_parser.set_defaults(func=predict)

    train_parser = subparsers.add_parser("train", help="Train from a prepared toxicity CSV dataset")
    train_parser.add_argument("--dataset", required=True, help="CSV path, e.g. Jigsaw train.csv")
    train_parser.add_argument("--augment-dataset", action="append", default=[], help="Optional extra CSV to append before training")
    train_parser.add_argument("--output", default=str(DEFAULT_MODEL_PATH), help="Where to save the trained model")
    train_parser.add_argument("--text-column", default="comment_text")
    train_parser.add_argument("--label-column", default="")
    train_parser.add_argument("--positive-threshold", type=float, default=0.5)
    train_parser.add_argument("--positive-values", default="", help="Comma-separated class values to treat as toxic, e.g. 0,1")
    train_parser.add_argument("--test-size", type=float, default=0.2)
    train_parser.add_argument("--max-features", type=int, default=120000)
    train_parser.set_defaults(func=train)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    return int(args.func(args))


if __name__ == "__main__":
    raise SystemExit(main())
