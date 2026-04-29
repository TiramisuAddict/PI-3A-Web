import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ml"))

import demande_adaptive_model as adaptive  # noqa: E402


class DemandeAdaptiveModelTest(unittest.TestCase):
    def test_required_select_without_option_is_kept_empty(self):
        result = adaptive.generate_autre_response(
            {
                "text": "demande de formation java",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "demande de formation externe java",
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_nom_formation": "Java",
                            "ai_type_formation": "Formation externe",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_nom_formation",
                                    "label": "Nom de la formation",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_type_formation",
                                    "label": "Type de formation",
                                    "type": "select",
                                    "required": True,
                                    "options": ["Formation interne", "Formation externe", "Certification"],
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            }
        )

        fields = {field["key"]: field for field in result["custom_fields"]}
        self.assertEqual("Java", result["details"].get("ai_nom_formation"))
        self.assertIn("ai_type_formation", fields)
        self.assertEqual("select", fields["ai_type_formation"]["type"])
        self.assertEqual("", fields["ai_type_formation"]["value"])
        self.assertNotIn("ai_type_formation", result["details"])
        self.assertFalse(result["needsLlmFallback"])

    def test_optional_field_without_prompt_evidence_is_not_selected(self):
        result = adaptive.generate_autre_response(
            {
                "text": "je veux un badge visiteur",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "je veux un badge visiteur pour dossier banque",
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_objet_demande": "badge visiteur",
                            "ai_justification_metier": "dossier banque",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_objet_demande",
                                    "label": "Objet de la demande",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_justification_metier",
                                    "label": "Justification metier",
                                    "type": "textarea",
                                    "required": False,
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            }
        )

        self.assertEqual("badge visiteur", result["details"].get("ai_objet_demande"))
        self.assertNotIn("ai_justification_metier", result["details"])
        self.assertNotIn("ai_justification_metier", [field["key"] for field in result["custom_fields"]])

    def test_deleted_generated_field_suppresses_matching_family(self):
        result = adaptive.generate_autre_response(
            {
                "text": "je veux un badge visiteur pour banque",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "je veux un badge visiteur pour banque",
                        "manual": True,
                        "createdAt": "2026-04-27T09:00:00+00:00",
                        "details": {
                            "ai_objet_demande": "badge visiteur",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_objet_demande",
                                    "label": "Objet de la demande",
                                    "type": "text",
                                    "required": True,
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                        "generatedSnapshot": {
                            "suggestedDetails": {
                                "ai_objet_demande": "badge visiteur",
                                "ai_justification_metier": "banque",
                            },
                            "dynamicFieldPlan": {
                                "add": [
                                    {
                                        "key": "ai_objet_demande",
                                        "label": "Objet de la demande",
                                        "type": "text",
                                        "required": True,
                                        "value": "badge visiteur",
                                    },
                                    {
                                        "key": "ai_justification_metier",
                                        "label": "Justification metier",
                                        "type": "textarea",
                                        "required": False,
                                        "value": "banque",
                                    },
                                ],
                                "remove": ["ALL"],
                                "replaceBase": True,
                            },
                        },
                    },
                    {
                        "prompt": "je veux un badge visiteur pour banque avec justification banque",
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_objet_demande": "badge visiteur",
                            "ai_justification_metier": "banque",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_objet_demande",
                                    "label": "Objet de la demande",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_justification_metier",
                                    "label": "Justification metier",
                                    "type": "textarea",
                                    "required": False,
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    },
                ],
            }
        )

        self.assertEqual("badge visiteur", result["details"].get("ai_objet_demande"))
        self.assertNotIn("ai_justification_metier", result["details"])
        self.assertNotIn("ai_justification_metier", [field["key"] for field in result["custom_fields"]])

    def test_deleted_generated_value_suppresses_matching_family(self):
        result = adaptive.generate_autre_response(
            {
                "text": "je veux un badge visiteur pour banque",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "je veux un badge visiteur pour banque",
                        "manual": True,
                        "createdAt": "2026-04-27T09:00:00+00:00",
                        "details": {
                            "ai_objet_demande": "badge visiteur",
                            "ai_justification_metier": "",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_objet_demande",
                                    "label": "Objet de la demande",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_justification_metier",
                                    "label": "Justification metier",
                                    "type": "textarea",
                                    "required": False,
                                    "value": "",
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                        "generatedSnapshot": {
                            "suggestedDetails": {
                                "ai_objet_demande": "badge visiteur",
                                "ai_justification_metier": "banque",
                            },
                            "dynamicFieldPlan": {
                                "add": [
                                    {
                                        "key": "ai_objet_demande",
                                        "label": "Objet de la demande",
                                        "type": "text",
                                        "required": True,
                                        "value": "badge visiteur",
                                    },
                                    {
                                        "key": "ai_justification_metier",
                                        "label": "Justification metier",
                                        "type": "textarea",
                                        "required": False,
                                        "value": "banque",
                                    },
                                ],
                                "remove": ["ALL"],
                                "replaceBase": True,
                            },
                        },
                    },
                    {
                        "prompt": "je veux un badge visiteur pour banque avec justification banque",
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_objet_demande": "badge visiteur",
                            "ai_justification_metier": "banque",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_objet_demande",
                                    "label": "Objet de la demande",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_justification_metier",
                                    "label": "Justification metier",
                                    "type": "textarea",
                                    "required": False,
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    },
                ],
            }
        )

        self.assertEqual("badge visiteur", result["details"].get("ai_objet_demande"))
        self.assertNotIn("ai_justification_metier", result["details"])
        self.assertNotIn("ai_justification_metier", [field["key"] for field in result["custom_fields"]])

    def test_exact_database_match_reuses_values_mentioned_in_prompt(self):
        result = adaptive.generate_autre_response(
            {
                "text": "demande nettoyage urgent apres degat (cafe renverse sur moquette)",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "demande nettoyage urgent apres degat (cafe renverse sur moquette)",
                        "confirmed": True,
                        "manual": False,
                        "createdAt": "2026-04-26T21:54:59+02:00",
                        "general": {
                            "titre": "demande nettoyage urgent apres degat (cafe renverse sur moquette)",
                            "description": "demande nettoyage urgent apres degat (cafe renverse sur moquette)",
                            "priorite": "HAUTE",
                            "categorie": "Autre",
                            "typeDemande": "Autre",
                        },
                        "details": {
                            "ai_type_d_intervention": "Nettoyage",
                            "ai_nature_du_degat_incident": "Cafe renverse",
                            "ai_surface_element_concerne": "Moquette",
                            "besoinPersonnalise": "demande nettoyage urgent apres degat (cafe renverse sur moquette)",
                            "descriptionBesoin": "demande nettoyage urgent apres degat (cafe renverse sur moquette)",
                            "niveauUrgenceAutre": "Urgente",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_type_d_intervention",
                                    "label": "Type intervention",
                                    "type": "text",
                                    "required": False,
                                },
                                {
                                    "key": "ai_nature_du_degat_incident",
                                    "label": "Nature du degat incident",
                                    "type": "text",
                                    "required": False,
                                },
                                {
                                    "key": "ai_surface_element_concerne",
                                    "label": "Surface element concerne",
                                    "type": "text",
                                    "required": False,
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                        "_learningSource": "database",
                    }
                ],
            }
        )

        self.assertFalse(result["needsLlmFallback"])
        self.assertEqual("Nettoyage", result["details"].get("ai_type_d_intervention"))
        self.assertEqual("Cafe renverse", result["details"].get("ai_nature_du_degat_incident"))
        self.assertEqual("Moquette", result["details"].get("ai_surface_element_concerne"))
        self.assertEqual(
            ["ai_type_d_intervention", "ai_nature_du_degat_incident", "ai_surface_element_concerne"],
            [field["key"] for field in result["custom_fields"]],
        )

    def test_manual_feedback_ignores_service_generated_fields(self):
        result = adaptive.generate_autre_response(
            {
                "text": "demande casque urgent",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "demande casque urgent",
                        "confirmed": True,
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_type_materiel": "Casque",
                            "ai_extra_infos": "urgent",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_type_materiel",
                                    "label": "Type materiel",
                                    "type": "text",
                                    "required": True,
                                    "source": "manual",
                                },
                                {
                                    "key": "ai_extra_infos",
                                    "label": "Extra infos",
                                    "type": "textarea",
                                    "required": True,
                                    "source": "generated",
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            }
        )

        self.assertEqual("Casque", result["details"].get("ai_type_materiel"))
        self.assertNotIn("ai_extra_infos", result["details"])
        self.assertEqual(["ai_type_materiel"], [field["key"] for field in result["custom_fields"]])

    def test_learned_sample_ignores_stale_field_plan_value_not_in_final_details(self):
        result = adaptive.generate_autre_response(
            {
                "text": "demande casque urgent",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "demande casque urgent",
                        "confirmed": True,
                        "manual": False,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_type_materiel": "Casque",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_type_materiel",
                                    "label": "Type materiel",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_extra_infos",
                                    "label": "Extra infos",
                                    "type": "textarea",
                                    "required": False,
                                    "value": "urgent",
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            }
        )

        self.assertEqual("Casque", result["details"].get("ai_type_materiel"))
        self.assertNotIn("ai_extra_infos", result["details"])
        self.assertEqual(["ai_type_materiel"], [field["key"] for field in result["custom_fields"]])

    def test_anchor_schema_prevents_compatible_samples_from_adding_fields(self):
        result = adaptive.generate_autre_response(
            {
                "text": "demande casque urgent",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "demande casque urgent",
                        "confirmed": True,
                        "manual": True,
                        "createdAt": "2026-04-27T11:00:00+00:00",
                        "details": {
                            "ai_type_materiel": "Casque",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_type_materiel",
                                    "label": "Type materiel",
                                    "type": "text",
                                    "required": True,
                                    "source": "manual",
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    },
                    {
                        "prompt": "demande casque urgent",
                        "confirmed": True,
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_type_materiel": "Casque",
                            "ai_extra_infos": "Casque",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_type_materiel",
                                    "label": "Type materiel",
                                    "type": "text",
                                    "required": True,
                                    "source": "manual",
                                },
                                {
                                    "key": "ai_extra_infos",
                                    "label": "Extra infos",
                                    "type": "textarea",
                                    "required": True,
                                    "source": "manual",
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    },
                ],
            }
        )

        self.assertEqual("Casque", result["details"].get("ai_type_materiel"))
        self.assertNotIn("ai_extra_infos", result["details"])
        self.assertEqual(["ai_type_materiel"], [field["key"] for field in result["custom_fields"]])

    def test_purpose_phrase_does_not_fill_organization_field(self):
        result = adaptive.generate_autre_response(
            {
                "text": "attestation de travail pour visa",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "attestation de travail pour visa",
                        "confirmed": True,
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_type_attestation": "attestation de travail",
                            "ai_motif_contexte": "visa",
                            "ai_organisme_destinataire": "visa",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_type_attestation",
                                    "label": "Type d attestation",
                                    "type": "text",
                                    "required": True,
                                    "source": "manual",
                                },
                                {
                                    "key": "ai_motif_contexte",
                                    "label": "Motif contexte",
                                    "type": "text",
                                    "required": False,
                                    "source": "manual",
                                },
                                {
                                    "key": "ai_organisme_destinataire",
                                    "label": "Organisme destinataire",
                                    "type": "text",
                                    "required": False,
                                    "source": "manual",
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            }
        )

        self.assertEqual("attestation de travail", result["details"].get("ai_type_attestation"))
        self.assertEqual("visa", result["details"].get("ai_motif_contexte"))
        self.assertNotIn("ai_organisme_destinataire", result["details"])
        self.assertNotIn("ai_organisme_destinataire", [field["key"] for field in result["custom_fields"]])

    def test_weak_database_match_requests_llm_fallback(self):
        result = adaptive.generate_autre_response(
            {
                "text": "demande d un transport par taxi pour une formation externe de html",
                "acceptedAutreFeedback": [
                    {
                        "prompt": "je veux un ecran 32 pouces",
                        "confirmed": True,
                        "manual": True,
                        "createdAt": "2026-04-27T10:00:00+00:00",
                        "details": {
                            "ai_materiel_concerne": "ecran",
                            "ai_specification": "32 pouces",
                        },
                        "fieldPlan": {
                            "add": [
                                {
                                    "key": "ai_materiel_concerne",
                                    "label": "Materiel concerne",
                                    "type": "text",
                                    "required": True,
                                },
                                {
                                    "key": "ai_specification",
                                    "label": "Specification",
                                    "type": "text",
                                    "required": False,
                                },
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            }
        )

        self.assertTrue(result["needsLlmFallback"])
        self.assertLess(result["dynamicFieldConfidence"]["score"], 50)


if __name__ == "__main__":
    unittest.main()
