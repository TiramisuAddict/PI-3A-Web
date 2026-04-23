import sys
import unittest
from pathlib import Path


ML_DIR = Path(__file__).resolve().parents[1] / "ml"
if str(ML_DIR) not in sys.path:
    sys.path.insert(0, str(ML_DIR))

import demande_ai_model as model


NEGATIVE_PROMPTS = [
    "merci de passer cette information",
    "demande de transformation de contrat",
    "je veux une information sur mon salaire",
    "besoin d informations generales",
]

POSITIVE_PROMPTS = [
    "je veux une formation html",
    "formation externe aws",
    "certification azure",
]


class DemandeFormationBoundaryTests(unittest.TestCase):
    def test_negative_prompts_do_not_trigger_formation(self):
        for prompt in NEGATIVE_PROMPTS:
            with self.subTest(prompt=prompt):
                intents = model._detect_intents(prompt)
                roles = model._detect_semantic_roles(prompt)
                fields = model._build_context_fields(prompt, intents)

                self.assertFalse(any(intent == "formation" for intent, _ in intents))
                self.assertFalse(roles["has_formation"])
                self.assertNotIn("nom_formation", fields)
                self.assertNotIn("type_formation", fields)

    def test_positive_prompts_trigger_formation(self):
        expected_types = {
            "formation externe aws": "Formation externe",
            "certification azure": "Certification",
        }

        for prompt in POSITIVE_PROMPTS:
            with self.subTest(prompt=prompt):
                intents = model._detect_intents(prompt)
                roles = model._detect_semantic_roles(prompt)
                fields = model._build_context_fields(prompt, intents)

                self.assertTrue(any(intent == "formation" for intent, _ in intents))
                self.assertTrue(roles["has_formation"])
                self.assertIn("nom_formation", fields)

                expected_type = expected_types.get(prompt)
                if expected_type:
                    self.assertEqual(fields.get("type_formation"), expected_type)

    def test_reimbursement_prompt_keeps_amount_and_skips_formation_fields(self):
        prompt = "je veux un remboursement de salaire montant 240dt merci de passer cette information"
        intents = model._detect_intents(prompt)
        fields = model._build_context_fields(prompt, intents)
        response = model._build_autre_response({}, prompt)

        self.assertEqual(fields.get("montant"), 240)
        self.assertFalse(any(intent == "formation" for intent, _ in intents))
        self.assertNotIn("nom_formation", fields)
        self.assertNotIn("type_formation", fields)
        custom_keys = {field.get("key") for field in response.get("custom_fields", [])}
        self.assertNotIn("ai_nom_formation", custom_keys)
        self.assertNotIn("ai_type_formation", custom_keys)


if __name__ == "__main__":
    unittest.main()