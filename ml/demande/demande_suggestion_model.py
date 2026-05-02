import json
import sys

import demande_ai_model as core


def main():
    raw = sys.stdin.read()
    if not raw.strip():
        print(json.dumps({"ok": False, "error": "Missing JSON input"}))
        return

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        print(json.dumps({"ok": False, "error": "Invalid JSON input"}))
        return

    if not isinstance(payload, dict):
        print(json.dumps({"ok": False, "error": "Payload must be a JSON object"}))
        return

    try:
        result = core._build_classification_response(payload, "")
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        return

    print(json.dumps({"ok": True, "result": result}, ensure_ascii=False))


if __name__ == "__main__":
    main()
