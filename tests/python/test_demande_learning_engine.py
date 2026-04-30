import sys
import unittest
from datetime import datetime, timedelta
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ml" / "demande"))

import demande_adaptive_model as adaptive  # noqa: E402
from extractors import extract_date, extract_entities, has_word  # noqa: E402


class DemandeLearningEngineTest(unittest.TestCase):
    def test_information_does_not_trigger_formation(self):
        self.assertFalse(has_word("information sur salaire", "formation"))

        result = adaptive.generate_autre_response(
            {"text": "information sur salaire", "acceptedAutreFeedback": []}
        )

        keys = [field["key"] for field in result["custom_fields"]]
        self.assertNotIn("ai_nom_formation", keys)
        self.assertNotIn("ai_type_formation", keys)

    def test_uniquement_en_bus_is_not_part_of_destination(self):
        entities = extract_entities("transport de tunis vers rades uniquement en bus")

        self.assertEqual("Tunis", entities.route_from)
        self.assertEqual("Rades", entities.route_to)
        self.assertIn("Uniquement bus", entities.constraints)

    def test_no_mission_added_when_word_is_absent(self):
        result = adaptive.generate_autre_response(
            {"text": "transport de tunis vers rades uniquement en bus", "acceptedAutreFeedback": []}
        )

        serialized = " ".join(str(value) for value in result["details"].values()).lower()
        self.assertNotIn("mission", serialized)
        self.assertEqual("Rades", result["details"].get("ai_lieu_souhaite"))

    def test_explicit_fields_are_not_dropped_by_max_field_limit(self):
        prompt = " ".join(f"valeur{index}" for index in range(10))
        feedback = {
            "prompt": prompt,
            "manual": True,
            "createdAt": "2026-04-27T10:00:00+00:00",
            "details": {f"ai_champ_{index}": f"valeur{index}" for index in range(10)},
            "fieldPlan": {
                "add": [
                    {
                        "key": f"ai_champ_{index}",
                        "label": f"Champ {index}",
                        "type": "text",
                        "required": True,
                    }
                    for index in range(10)
                ],
                "remove": ["ALL"],
                "replaceBase": True,
            },
        }
        result = adaptive.generate_autre_response({"text": prompt, "acceptedAutreFeedback": [feedback]})

        self.assertGreaterEqual(len(result["custom_fields"]), 10)
        self.assertEqual({f"ai_champ_{index}" for index in range(10)}, {field["key"] for field in result["custom_fields"]})

    def test_select_has_no_non_empty_default_without_evidence(self):
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
        self.assertEqual("", fields["ai_type_formation"]["value"])
        self.assertNotIn("ai_type_formation", result["details"])

    def test_acceptance_prompts(self):
        keyboard = adaptive.generate_autre_response(
            {"text": "clavier ergonomique pour tendinite", "acceptedAutreFeedback": []}
        )
        self.assertEqual("clavier", keyboard["details"].get("ai_materiel_concerne"))
        self.assertNotIn("ai_type_transport", keyboard["details"])
        self.assertNotIn("ai_zone_souhaitee", keyboard["details"])

        attestation = adaptive.generate_autre_response(
            {"text": "attestation de salaire pour banque", "acceptedAutreFeedback": []}
        )
        self.assertEqual("attestation de salaire", attestation["details"].get("ai_type_attestation"))
        self.assertNotIn("ai_type_depense", attestation["details"])

        taxi = adaptive.generate_autre_response(
            {"text": "remboursement taxi 85 tnd mission client", "acceptedAutreFeedback": []}
        )
        self.assertEqual("85", taxi["details"].get("ai_montant"))
        self.assertNotIn("Client", taxi["details"].values())
        self.assertIn("Mission client", taxi["details"].get("ai_justification_metier", ""))

    def test_duplicate_same_value_learned_fields_keep_one_best_field(self):
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
                    {
                        "prompt": "besoin casque pour appel urgent",
                        "confirmed": True,
                        "manual": True,
                        "createdAt": "2026-04-27T11:00:00+00:00",
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

    def test_shift_prompt_does_not_create_material_current_or_generic_date_fields(self):
        bad_shift_feedback = {
            "prompt": "je veux un shift nuit 22h-6h uniquement pendant la semaine prochaine",
            "confirmed": True,
            "manual": True,
            "createdAt": "2026-04-27T10:00:00+00:00",
            "details": {
                "ai_type_demande": "Shift de nuit",
                "ai_shift_souhaite": "Nuit",
                "ai_horaire_souhaite": "22h-6h",
                "ai_periode_concernee": "Semaine prochaine",
                "ai_materiel_concerne": "shift nuit 22h 6h",
                "ai_horaire_actuel": "22h",
                "ai_date_souhaitee": "2026-05-07",
            },
            "fieldPlan": {
                "add": [
                    {"key": "ai_type_demande", "label": "Type de demande", "type": "text", "required": True},
                    {"key": "ai_shift_souhaite", "label": "Shift souhaite", "type": "text", "required": True},
                    {"key": "ai_horaire_souhaite", "label": "Horaire souhaite", "type": "text", "required": True},
                    {"key": "ai_periode_concernee", "label": "Periode concernee", "type": "text", "required": True},
                    {"key": "ai_materiel_concerne", "label": "Materiel concerne", "type": "text", "required": True},
                    {"key": "ai_horaire_actuel", "label": "Horaire actuel", "type": "text", "required": False},
                    {"key": "ai_date_souhaitee", "label": "Date souhaitee", "type": "date", "required": False},
                ],
                "remove": ["ALL"],
                "replaceBase": True,
            },
        }

        result = adaptive.generate_autre_response(
            {
                "text": "je veux un shift nuit 22h-6h uniquement pendant la semaine prochaine",
                "acceptedAutreFeedback": [bad_shift_feedback],
            }
        )
        expected_next_week = (datetime.now().date() + timedelta(days=7)).isoformat()

        self.assertEqual("Shift de nuit", result["details"].get("ai_type_demande"))
        self.assertEqual("Nuit", result["details"].get("ai_shift_souhaite"))
        self.assertEqual("22h-6h", result["details"].get("ai_horaire_souhaite"))
        self.assertEqual(expected_next_week, result["details"].get("ai_date_souhaitee"))
        self.assertNotIn("ai_materiel_concerne", result["details"])
        self.assertNotIn("ai_horaire_actuel", result["details"])
        self.assertNotIn("ai_periode_concernee", result["details"])

    def test_relative_dates_and_weekdays_are_resolved_from_current_day(self):
        base = datetime(2026, 4, 29)  # Wednesday

        self.assertEqual("2026-04-30", extract_date("demain", base))
        self.assertEqual("2026-05-06", extract_date("semaine prochaine", base))
        self.assertEqual("2026-05-02", extract_date("samedi", base))
        self.assertEqual("2026-05-03", extract_date("dimanche", base))
        self.assertEqual("2026-04-30", extract_date("apres un jour", base))

        result = adaptive.generate_autre_response(
            {"text": "je veux un shift nuit 22h-6h demain", "acceptedAutreFeedback": []}
        )
        expected_tomorrow = (datetime.now().date() + timedelta(days=1)).isoformat()
        self.assertEqual(expected_tomorrow, result["details"].get("ai_date_souhaitee"))

    def test_explicit_transport_date_and_formation_fields_are_merged_after_db_match(self):
        training_feedback = {
            "prompt": "formation professionnelle en ui ux",
            "confirmed": True,
            "manual": True,
            "createdAt": "2026-04-27T10:00:00+00:00",
            "details": {
                "ai_nom_formation": "UI UX",
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
                        "required": False,
                        "options": ["Formation interne", "Formation externe", "Certification"],
                    },
                ],
                "remove": ["ALL"],
                "replaceBase": True,
            },
        }

        result = adaptive.generate_autre_response(
            {
                "text": "je veux une demande de transport pour une formation html externe le 21 mai de hammamlif a sousse avec une taxi",
                "acceptedAutreFeedback": [training_feedback],
            }
        )

        self.assertEqual("Html", result["details"].get("ai_nom_formation"))
        self.assertEqual("Formation externe", result["details"].get("ai_type_formation"))
        self.assertEqual("Hammamlif", result["details"].get("ai_lieu_depart_actuel"))
        self.assertEqual("Sousse", result["details"].get("ai_lieu_souhaite"))
        self.assertEqual("Taxi", result["details"].get("ai_type_transport"))
        self.assertRegex(result["details"].get("ai_date_souhaitee", ""), r"^\d{4}-05-21$")

    def test_schedule_prompt_does_not_keep_wrong_empty_system_field(self):
        wrong_schema_feedback = {
            "prompt": "acces vpn pour rendez-vous medical",
            "confirmed": True,
            "manual": True,
            "createdAt": "2026-04-27T10:00:00+00:00",
            "details": {
                "ai_systeme_concerne": "VPN",
                "ai_justification_metier": "Rendez-vous medical",
            },
            "fieldPlan": {
                "add": [
                    {
                        "key": "ai_systeme_concerne",
                        "label": "Systeme concerne",
                        "type": "text",
                        "required": True,
                    },
                    {
                        "key": "ai_justification_metier",
                        "label": "Justification",
                        "type": "textarea",
                        "required": False,
                    },
                ],
                "remove": ["ALL"],
                "replaceBase": True,
            },
        }

        result = adaptive.generate_autre_response(
            {
                "text": "amenagement d horaire pour rendez-vous medical tous les mercredis: depart a 16h",
                "acceptedAutreFeedback": [wrong_schema_feedback],
            }
        )

        self.assertNotIn("ai_systeme_concerne", result["details"])
        self.assertEqual("16h", result["details"].get("ai_horaire_souhaite"))
        self.assertEqual("Tous les mercredis", result["details"].get("ai_periode_concernee"))
        self.assertEqual("rendez-vous medical", result["details"].get("ai_motif_changement"))

    def test_cleaning_damage_prompt_uses_maintenance_fields_not_custom_object(self):
        result = adaptive.generate_autre_response(
            {"text": "demande nettoyage urgent apres degat (cafe renverse sur moquette)", "acceptedAutreFeedback": []}
        )

        self.assertEqual("Nettoyage", result["details"].get("ai_type_intervention"))
        self.assertEqual("Cafe Renverse", result["details"].get("ai_nature_incident"))
        self.assertEqual("Moquette", result["details"].get("ai_equipement_concerne"))
        self.assertNotIn("ai_materiel_concerne", result["details"])


if __name__ == "__main__":
    unittest.main()
