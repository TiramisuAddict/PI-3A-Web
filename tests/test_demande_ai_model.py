import sys
import unittest
from calendar import monthrange
from datetime import datetime, timedelta
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
    "la deformation du badge bloque mon acces",
    "merci pour la reformation du document administratif",
]

POSITIVE_PROMPTS = [
    "je veux une formation html",
    "formation externe aws",
    "certification azure",
]


class DemandeFormationBoundaryTests(unittest.TestCase):
    def _find_objet_value(self, custom_fields):
        for field in custom_fields or []:
            key = model._norm((field or {}).get("key", "")).replace("_", " ")
            if "objet" in key:
                return str((field or {}).get("value", "")).strip()
        return ""

    def _add_months(self, date_value, months):
        month_index = date_value.month - 1 + months
        year = date_value.year + (month_index // 12)
        month = (month_index % 12) + 1
        day = min(date_value.day, monthrange(year, month)[1])
        return date_value.replace(year=year, month=month, day=day)

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

    def test_varied_transport_formation_prompt_extracts_core_context(self):
        prompt = "je souhaite demande un transport en bus pour une formation professionnelle en ui/ix a hammam-lif qui debute le 12 decembre"
        intents = model._detect_intents(prompt)
        fields = model._build_context_fields(prompt, intents)

        self.assertTrue(any(intent == "transport" for intent, _ in intents))
        self.assertTrue(any(intent == "formation" for intent, _ in intents))
        self.assertEqual(fields.get("type_transport_souhaite"), "Bus")
        self.assertEqual(fields.get("type_formation"), "Formation externe")
        self.assertEqual(fields.get("date_souhaitee"), "2026-12-12")
        self.assertEqual(fields.get("lieu_souhaite"), "Hammam-lif")

        formation_name = fields.get("nom_formation", "")
        self.assertIn("Ui/ix", formation_name)
        self.assertNotIn("Qui", formation_name)
        self.assertNotIn("Hammam-lif", formation_name)

    def test_transport_for_formation_does_not_attach_route_city_to_formation_name(self):
        prompt = "demande de transport pour une formation java de tunis vers rades le 21 mai"
        intents = model._detect_intents(prompt)
        fields = model._build_context_fields(prompt, intents)

        self.assertEqual(fields.get("lieu_depart_actuel"), "Tunis")
        self.assertEqual(fields.get("lieu_souhaite"), "Rades")
        self.assertEqual(fields.get("type_transport_souhaite"), "A definir")
        self.assertIn("Java", fields.get("nom_formation", ""))
        self.assertNotIn("Tunis", fields.get("nom_formation", ""))

        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        normalized_formation = model._norm((custom_fields.get("ai_nom_formation") or {}).get("value", ""))
        self.assertIn("java", normalized_formation)
        self.assertNotIn("tunis", normalized_formation)

    def test_autre_response_strips_template_boilerplate_and_generic_formation_prefixes(self):
        prompt = (
            "Bonjour, je souhaite demander transport de tunis vers sfax pour formation professionnel de javafx. "
            "Mon besoin concerne precisement transport de tunis vers sfax pour formation professionnel de javafx "
            "dans le cadre de mon activite professionnelle. Type: Autre. Categorie: Autre. "
            "Je reste disponible pour tout complement d information."
        )

        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual((custom_fields.get("ai_lieu_depart_actuel") or {}).get("value"), "Tunis")
        self.assertEqual((custom_fields.get("ai_lieu_souhaite") or {}).get("value"), "Sfax")
        self.assertEqual((custom_fields.get("ai_nom_formation") or {}).get("value"), "Javafx")
        self.assertNotIn("mon besoin concerne", model._norm(response.get("correctedText", "")))

    def test_description_generation_no_longer_returns_template_style_extra_info(self):
        response = model._build_description_response(
            {"title": "Demande de transport de Tunis vers Sfax", "typeDemande": "Autre", "categorie": "Autre"},
            "",
        )

        description = str(response.get("description", ""))
        self.assertNotIn("Bonjour", description)
        self.assertNotIn("Type:", description)
        self.assertNotIn("Categorie:", description)
        self.assertIn("transport de tunis vers sfax", model._norm(description))

    def test_date_location_and_keywords_are_prompt_agnostic(self):
        prompts = [
            "transport bus pour formation ui/ux a tunis le 12/12",
            "j ai besoin d un trajet en train vers hammam lif demain pour certification devops",
            "mission a sfax apres demain avec deplacement en taxi",
        ]

        for prompt in prompts:
            with self.subTest(prompt=prompt):
                intents = model._detect_intents(prompt)
                fields = model._build_context_fields(prompt, intents)

                self.assertTrue(fields.get("date_souhaitee"))
                if "vers" in prompt or " a " in prompt:
                    self.assertTrue(fields.get("lieu_souhaite"))

                keywords = fields.get("mots_cles") or []
                self.assertTrue(len(keywords) >= 3)

        # short/slash technical tokens should be preserved in keyword extraction
        intents = model._detect_intents("formation ui/ux et acces api")
        fields = model._build_context_fields("formation ui/ux et acces api", intents)
        keywords = [k.lower() for k in (fields.get("mots_cles") or [])]
        self.assertTrue(any("ui/ux" == k or "ui" == k for k in keywords))
        self.assertIn("api", keywords)

    def test_relative_dates_are_calculated_from_today(self):
        today = datetime.now().date()
        tomorrow = (today + timedelta(days=1)).isoformat()
        next_month = self._add_months(today, 1).isoformat()

        prompt_tomorrow = "demande d acces vpn demain"
        response_tomorrow = model._build_autre_response(
            {"text": prompt_tomorrow, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields_tomorrow = {field.get("key"): field for field in response_tomorrow.get("custom_fields", [])}
        self.assertEqual((custom_fields_tomorrow.get("ai_date_souhaitee_metier") or {}).get("value"), tomorrow)

        prompt_month = "demande d acces vpn dans 1 mois"
        fields_month = model._build_context_fields(prompt_month, model._detect_intents(prompt_month))
        self.assertEqual(fields_month.get("date_souhaitee"), next_month)

        prompt_month_later = "demande d acces vpn 1 mois plus tard"
        response_month_later = model._build_autre_response(
            {"text": prompt_month_later, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields_month_later = {field.get("key"): field for field in response_month_later.get("custom_fields", [])}
        self.assertEqual((custom_fields_month_later.get("ai_date_souhaitee_metier") or {}).get("value"), next_month)

    def test_duration_only_schedule_prompt_does_not_invent_start_date(self):
        prompt = "demande d amenagement d horaire arriver a 10h partir a 19h pendant 1 mois"

        fields = model._build_context_fields(prompt, model._detect_intents(prompt))
        self.assertTrue(fields.get("date_souhaitee"))

        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertNotIn("ai_date_souhaitee_metier", custom_fields)
        self.assertNotIn("ai_date_fin_extra", custom_fields)
        self.assertFalse((response.get("details") or {}).get("dateSouhaiteeAutre"))

    def test_autre_response_is_model_driven_and_self_contained(self):
        payload = {
            "text": "je souhaite demande un transport en bus pour une formation professionnelle en ui/ix a hammam-lif qui debute le 12 decembre",
            "general": {
                "typeDemande": "Autre",
                "categorie": "Autre",
            },
            "details": {},
        }

        response = model._build_autre_response(payload, "")

        self.assertEqual(response.get("remove_fields"), ["ALL"])
        self.assertTrue(response.get("replace_base"))

        general = response.get("general", {})
        self.assertEqual(general.get("priorite"), "NORMALE")
        self.assertTrue(general.get("titre"))

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual(custom_fields["ai_type_transport"]["value"], "Bus")
        self.assertEqual(custom_fields["ai_lieu_souhaite"]["value"], "Hammam-lif")
        self.assertEqual(custom_fields["ai_date_souhaitee_metier"]["value"], "2026-12-12")
        self.assertIn("Ui/ix", custom_fields["ai_nom_formation"]["value"])

    def test_transport_type_stays_undecided_when_prompt_does_not_specify_it(self):
        payload = {
            "text": "bonjour je veux une demande de transport pour une formation professionelle de java",
            "general": {
                "typeDemande": "Autre",
                "categorie": "Autre",
            },
            "details": {},
        }

        response = model._build_autre_response(payload, "")
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertIn("ai_type_transport", custom_fields)
        self.assertEqual(custom_fields["ai_type_transport"]["value"], "A definir")
        self.assertIn("Bus", custom_fields["ai_type_transport"].get("options", []))
        self.assertIn("Taxi", custom_fields["ai_type_transport"].get("options", []))
        self.assertIn("A definir", custom_fields["ai_type_transport"].get("options", []))

    def test_autre_runtime_training_sources_do_not_use_static_seed_samples(self):
        source_paths = [str(path) for path in model._autre_source_paths()]
        self.assertFalse(any("autre_seed_samples.json" in path for path in source_paths))

    def test_unknown_demande_generates_dynamic_custom_fields_and_title(self):
        payload = {
            "text": "demande speciale; projet: atlas; livrable: maquette finale; contrainte: validation client avant 2026-09-01",
            "general": {
                "typeDemande": "Autre",
                "categorie": "Autre",
            },
            "details": {},
        }

        response = model._build_autre_response(payload, "")
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertIn("ai_custom_projet", custom_fields)
        self.assertEqual(custom_fields["ai_custom_projet"]["value"], "atlas")
        self.assertIn("ai_custom_livrable", custom_fields)
        self.assertIn("maquette finale", custom_fields["ai_custom_livrable"]["value"].lower())
        self.assertNotIn("ai_salle_souhaitee", custom_fields)
        self.assertNotIn("ai_poste_souhaite", custom_fields)

        title = (response.get("general", {}) or {}).get("titre", "").lower()
        self.assertIn("atlas", title)

    def test_finance_with_maladie_keeps_justification_without_leave_or_hotel_fields(self):
        prompt = "remboursement de salaire 250dt pour raison de maladie"
        response = model._build_autre_response({"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}}, "")
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        amount_like = custom_fields.get("ai_montant") or {}
        justification_like = custom_fields.get("ai_justification_metier") or {}

        self.assertEqual(amount_like.get("value"), "250")
        self.assertIn("maladie", justification_like.get("value", "").lower())
        self.assertNotIn("ai_type_conge", custom_fields)
        self.assertNotIn("ai_date_debut_conge", custom_fields)
        self.assertNotIn("ai_date_fin_conge", custom_fields)
        self.assertNotIn("Hotel", [str(field.get("value", "")) for field in custom_fields.values()])

    def test_leave_prompt_generates_leave_fields_when_conge_is_explicit(self):
        prompt = "conge maladie du 1 au 3 juin"
        response = model._build_autre_response({"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}}, "")
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual(custom_fields["ai_type_conge"]["value"], "Conge maladie")
        self.assertTrue(custom_fields["ai_date_debut_conge"]["value"])
        self.assertTrue(custom_fields["ai_date_fin_conge"]["value"])

    def test_hotel_reimbursement_generates_expense_type_when_explicit(self):
        prompt = "remboursement hotel 300dt"
        response = model._build_autre_response({"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}}, "")
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual(custom_fields["ai_type_depense"]["value"], "Hotel")
        self.assertEqual(custom_fields["ai_montant"]["value"], "300")

    def test_mission_client_does_not_create_location_or_beneficiary(self):
        prompt = "remboursement de 85 tnd pour taxi le 14 fevrier 2027 mission client"

        intents = model._detect_intents(prompt)
        fields = model._build_context_fields(prompt, intents)
        self.assertEqual(fields.get("montant"), 85)
        self.assertEqual(fields.get("type_transport_souhaite"), "Taxi")
        self.assertEqual(fields.get("date_souhaitee"), "2027-02-14")
        self.assertNotIn("lieu_souhaite", fields)
        self.assertNotIn("beneficiaire", fields)

        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        justification = (custom_fields.get("ai_justification_metier") or {}).get("value", "")
        justification_norm = model._norm(justification)
        self.assertIn("mission client", justification_norm)
        self.assertNotIn("mission a client", justification_norm)
        self.assertFalse(any("beneficiaire" in str(key) for key in custom_fields.keys()))

    def test_mission_with_explicit_preposition_keeps_location(self):
        prompt = "remboursement taxi 85 tnd mission a Paris le 14 fevrier 2027"
        fields = model._build_context_fields(prompt, model._detect_intents(prompt))

        self.assertEqual(fields.get("lieu_souhaite"), "Paris")

    def test_beneficiary_requires_explicit_person_evidence(self):
        prompt = "remboursement 85 tnd taxi pour Ahmed Ben Ali le 14 fevrier 2027"
        fields = model._build_context_fields(prompt, model._detect_intents(prompt))

        self.assertEqual(fields.get("beneficiaire"), "Ahmed Ben Ali")

    def test_objet_reimbursement_taxi_is_not_redundant(self):
        prompt = "remboursement de 85 tnd pour taxi le 14 fevrier 2027 mission client"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        objet = self._find_objet_value(response.get("custom_fields", []))
        if objet:
            objet_norm = model._norm(objet)
            self.assertNotEqual(objet_norm, "taxi")
            self.assertTrue("frais de taxi" in objet_norm or "mission client" in objet_norm)

    def test_objet_reimbursement_restaurant_is_refined(self):
        prompt = "remboursement 120 dt restaurant client le 3 mai 2027"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        objet = self._find_objet_value(response.get("custom_fields", []))
        self.assertEqual(model._norm(objet), "frais de restaurant")

    def test_objet_does_not_duplicate_nom_formation(self):
        prompt = "formation professionnel de html"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = response.get("custom_fields", [])
        objet = self._find_objet_value(custom_fields)
        nom_formation = ""
        for field in custom_fields:
            if str((field or {}).get("key", "")).strip() == "ai_nom_formation":
                nom_formation = str((field or {}).get("value", "")).strip()
                break

        if objet and nom_formation:
            self.assertNotEqual(model._norm(objet), model._norm(nom_formation))

    def test_no_constraint_field_without_explicit_constraint_evidence(self):
        prompt = "remboursement 85 tnd taxi mission client le 14 fevrier 2027"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual((custom_fields.get("ai_type_transport") or {}).get("value"), "Taxi")
        self.assertFalse(any("contrainte" in model._norm(str(key)) for key in custom_fields.keys()))

    def test_constraint_field_allowed_with_explicit_uniquement_bus(self):
        prompt = "transport vers rades uniquement en bus"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual((custom_fields.get("ai_type_transport") or {}).get("value"), "Bus")

        constraint_value = ""
        for key, field in custom_fields.items():
            if "contrainte" in model._norm(str(key)):
                constraint_value = str((field or {}).get("value", "")).strip()
                break

        if constraint_value:
            self.assertIn("bus", model._norm(constraint_value))

    def test_constraint_field_extracts_sans_taxi(self):
        prompt = "transport sans taxi"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        constraint_value = ""
        for key, field in custom_fields.items():
            if "contrainte" in model._norm(str(key)):
                constraint_value = str((field or {}).get("value", "")).strip()
                break

        self.assertTrue(constraint_value)
        self.assertEqual(model._norm(constraint_value), "sans taxi")

    def test_transport_route_uniquement_bus_destination_and_justification(self):
        prompt = "transport de tunis vers rades uniquement en bus"

        fields = model._build_context_fields(prompt, model._detect_intents(prompt))
        self.assertEqual(fields.get("lieu_depart_actuel"), "Tunis")
        self.assertEqual(fields.get("lieu_souhaite"), "Rades")
        self.assertEqual(fields.get("type_transport_souhaite"), "Bus")

        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual((custom_fields.get("ai_lieu_souhaite") or {}).get("value"), "Rades")
        self.assertEqual((custom_fields.get("ai_type_transport") or {}).get("value"), "Bus")
        justification = (custom_fields.get("ai_justification_metier") or {}).get("value", "")
        self.assertNotIn("mission", model._norm(justification))

    def test_transport_route_en_taxi_destination_without_pollution(self):
        prompt = "transport de tunis vers rades en taxi"

        fields = model._build_context_fields(prompt, model._detect_intents(prompt))
        self.assertEqual(fields.get("lieu_souhaite"), "Rades")
        self.assertEqual(fields.get("type_transport_souhaite"), "Taxi")

        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual((custom_fields.get("ai_lieu_souhaite") or {}).get("value"), "Rades")
        self.assertEqual((custom_fields.get("ai_type_transport") or {}).get("value"), "Taxi")
        justification = (custom_fields.get("ai_justification_metier") or {}).get("value", "")
        self.assertNotIn("mission", model._norm(justification))

    def test_parking_badge_prompt_does_not_leak_unrelated_zone(self):
        prompt = "demande d acces parking souterrain badge parking"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual((custom_fields.get("ai_systeme_concerne") or {}).get("value"), "Parking Souterrain Badge Parking")
        zone = (custom_fields.get("ai_zone_souhaitee") or {}).get("value", "")
        self.assertFalse(zone)

    def test_stationnement_prompt_without_zone_does_not_reuse_old_zone_value(self):
        prompt = "autorisation de stationnement pour un visiteur le 5 mai 2027 de 10h a 14h"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual((custom_fields.get("ai_type_stationnement") or {}).get("value"), "Place reservee")
        self.assertFalse((custom_fields.get("ai_zone_souhaitee") or {}).get("value"))
        self.assertEqual((custom_fields.get("ai_date_souhaitee_metier") or {}).get("value"), "2027-05-05")

    def test_schedule_change_prompt_extracts_horaires_and_date(self):
        prompt = "demande de changement d horaire je veux passer de 9h-18h a 8h-17h a partir du 1er mai 2027"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual((custom_fields.get("ai_horaire_actuel") or {}).get("value"), "9h-18h")
        self.assertEqual((custom_fields.get("ai_horaire_souhaite") or {}).get("value"), "8h-17h")
        self.assertEqual((custom_fields.get("ai_date_souhaitee_metier") or {}).get("value"), "2027-05-01")

    def test_vpn_access_prompt_does_not_leak_unrelated_custom_objet(self):
        prompt = "besoin d acces vpn urgent pour travailler a distance des demain"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertIn("vpn", model._norm((custom_fields.get("ai_systeme_concerne") or {}).get("value", "")))
        self.assertNotIn("ai_custom_objet", custom_fields)

    def test_sharepoint_access_prompt_stays_prompt_supported(self):
        prompt = "demande d acces a sharepoint pour consulter les documents de projet"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertIn("sharepoint", model._norm((custom_fields.get("ai_systeme_concerne") or {}).get("value", "")))
        self.assertIn("consulter", model._norm((custom_fields.get("ai_justification_metier") or {}).get("value", "")))
        for field in custom_fields.values():
            field_value = model._norm(field.get("value", ""))
            self.assertNotIn("formation", field_value)
            self.assertNotIn("javascript", field_value)

    def test_confirmed_exact_feedback_still_requires_confirmation_for_stable_prompt(self):
        prompt = "parking pres de l entree entorse"
        payload = {
            "text": prompt,
            "general": {"typeDemande": "Autre", "categorie": "Autre"},
            "acceptedAutreFeedback": [
                {
                    "prompt": prompt,
                    "general": {
                        "titre": "Demande de parking Pres de l'entree",
                        "description": "Parking pres de l entree entorse",
                        "priorite": "NORMALE",
                        "categorie": "Autre",
                        "typeDemande": "Autre",
                    },
                    "details": {
                        "besoinPersonnalise": "Demande de parking Pres de l'entree",
                        "descriptionBesoin": "Parking pres de l entree entorse",
                        "niveauUrgenceAutre": "Normale",
                        "ai_zone_souhaitee": "Pres de l'entree",
                        "ai_type_stationnement": "Place reservee",
                    },
                    "fieldPlan": {
                        "add": [
                            {
                                "key": "ai_zone_souhaitee",
                                "label": "Zone souhaitee",
                                "type": "text",
                                "required": True,
                                "value": "Pres de l'entree",
                            },
                            {
                                "key": "ai_type_stationnement",
                                "label": "Type de stationnement",
                                "type": "select",
                                "required": True,
                                "value": "Place reservee",
                                "options": ["Place reservee", "Acces parking", "Autorisation temporaire", "Autre"],
                            },
                        ],
                        "remove": [],
                        "replaceBase": False,
                    },
                }
            ],
        }

        response = model._build_autre_response(payload, "")
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertFalse(response.get("skipConfirmationRestriction"))
        self.assertEqual((response.get("dynamicFieldConfidence") or {}).get("label"), "Elevee")
        self.assertEqual(model._norm((custom_fields.get("ai_zone_souhaitee") or {}).get("value", "")), model._norm("Pres de l'entree"))

    def test_confirmed_exact_feedback_keeps_confirmation_for_relative_time_prompt(self):
        prompt = "transport de tunis vers rades uniquement en bus demain a 8 h"
        payload = {
            "text": prompt,
            "general": {"typeDemande": "Autre", "categorie": "Autre"},
            "acceptedAutreFeedback": [
                {
                    "prompt": prompt,
                    "general": {
                        "titre": "Transport de Tunis vers Rades",
                        "description": "Transport de tunis vers rades uniquement en bus demain a 8 h",
                        "priorite": "NORMALE",
                        "categorie": "Autre",
                        "typeDemande": "Autre",
                    },
                    "details": {
                        "besoinPersonnalise": "Transport de Tunis vers Rades",
                        "descriptionBesoin": "Transport de tunis vers rades uniquement en bus demain a 8 h",
                        "niveauUrgenceAutre": "Normale",
                        "ai_lieu_depart_actuel": "Tunis",
                        "ai_lieu_souhaite": "Rades",
                        "ai_type_transport": "Bus",
                    },
                    "fieldPlan": {
                        "add": [
                            {"key": "ai_lieu_depart_actuel", "label": "Lieu de depart actuel", "type": "text", "required": True, "value": "Tunis"},
                            {"key": "ai_lieu_souhaite", "label": "Lieu souhaite", "type": "text", "required": True, "value": "Rades"},
                            {"key": "ai_type_transport", "label": "Type de transport", "type": "select", "required": False, "value": "Bus", "options": ["A definir", "Bus", "Train"]},
                        ],
                        "remove": [],
                        "replaceBase": False,
                    },
                }
            ],
        }

        response = model._build_autre_response(payload, "")

        self.assertFalse(response.get("skipConfirmationRestriction"))
        self.assertEqual((response.get("dynamicFieldConfidence") or {}).get("label"), "Elevee")

    def test_learned_feedback_uses_edited_detail_values_over_old_field_plan_values(self):
        prompt = "Demande de transport le 21 mai en voiture, la raison est de voir ma famille"
        response = model._build_autre_response(
            {
                "text": prompt,
                "general": {"typeDemande": "Autre", "categorie": "Autre"},
                "acceptedAutreFeedback": [
                    {
                        "prompt": "Demande de transport le 21 mai par voiture, la raison est de voir ma famille",
                        "general": {
                            "titre": "Transport - objet: voir la famille",
                            "description": "Demande de transport le 21 mai en voiture, la raison est de voir ma famille",
                            "priorite": "NORMALE",
                            "categorie": "Autre",
                            "typeDemande": "Autre",
                        },
                        "details": {
                            "ai_custom_objet": "voir ma famille",
                            "ai_date_souhaitee_metier": "2026-05-21",
                            "ai_type_transport": "Voiture",
                            "ai_justification_metier": "voir ma famille",
                        },
                        "fieldPlan": {
                            "add": [
                                {"key": "ai_custom_objet", "label": "Custom Objet", "type": "text", "required": False, "value": "Demande"},
                                {"key": "ai_date_souhaitee_metier", "label": "Date souhaitee", "type": "date", "required": False, "value": "2026-05-21"},
                                {
                                    "key": "ai_type_transport",
                                    "label": "Type de transport",
                                    "type": "select",
                                    "required": False,
                                    "value": "Voiture de service",
                                    "options": ["A definir", "Bus", "Train", "Voiture", "Vehicule", "Taxi", "Navette"],
                                },
                                {"key": "ai_justification_metier", "label": "Justification", "type": "textarea", "required": False, "value": "voir ma famille"},
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            },
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        self.assertEqual((custom_fields.get("ai_custom_objet") or {}).get("value"), "Voir ma famille")
        self.assertEqual((custom_fields.get("ai_type_transport") or {}).get("value"), "Voiture")
        self.assertIn("Voiture", (custom_fields.get("ai_type_transport") or {}).get("options", []))
        self.assertNotEqual((custom_fields.get("ai_custom_objet") or {}).get("value"), "Demande")

    def test_learned_material_schema_does_not_turn_screen_size_into_quantity(self):
        feedback = {
            "prompt": "je veux un ecran 32 pouces pour travail sur design",
            "general": {"typeDemande": "Autre", "categorie": "Autre"},
            "details": {
                "ai_type_de_materiel": "ecran",
                "ai_specification_modele_souhaite": "32 pouces",
                "ai_usage_justification_metier": "travail sur design",
            },
            "fieldPlan": {
                "add": [
                    {"key": "ai_type_de_materiel", "label": "Type de materiel", "type": "text", "required": False, "value": "ecran"},
                    {"key": "ai_specification_modele_souhaite", "label": "Specification / modele souhaite", "type": "text", "required": False, "value": "32 pouces"},
                    {"key": "ai_usage_justification_metier", "label": "Usage / justification metier", "type": "textarea", "required": False, "value": "travail sur design"},
                ],
                "remove": ["ALL"],
                "replaceBase": True,
            },
        }

        response = model._build_autre_response(
            {
                "text": "je veux un ecran 32 pouces pour travail sur design",
                "general": {"typeDemande": "Autre", "categorie": "Autre"},
                "acceptedAutreFeedback": [feedback],
            },
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual((custom_fields.get("ai_type_de_materiel") or {}).get("value"), "Ecran")
        self.assertEqual(model._norm((custom_fields.get("ai_specification_modele_souhaite") or {}).get("value")), "32 pouces")
        self.assertNotIn("ai_quantite", custom_fields)

    def test_learned_material_schema_matches_synonym_and_updates_specification(self):
        response = model._build_autre_response(
            {
                "text": "je veux un moniteur 27 pouces pour design",
                "general": {"typeDemande": "Autre", "categorie": "Autre"},
                "acceptedAutreFeedback": [
                    {
                        "prompt": "je veux un ecran 32 pouces pour travail sur design",
                        "general": {"typeDemande": "Autre", "categorie": "Autre"},
                        "details": {
                            "ai_type_de_materiel": "ecran",
                            "ai_specification_modele_souhaite": "32 pouces",
                            "ai_usage_justification_metier": "travail sur design",
                        },
                        "fieldPlan": {
                            "add": [
                                {"key": "ai_type_de_materiel", "label": "Type de materiel", "type": "text", "required": False, "value": "ecran"},
                                {"key": "ai_specification_modele_souhaite", "label": "Specification / modele souhaite", "type": "text", "required": False, "value": "32 pouces"},
                                {"key": "ai_usage_justification_metier", "label": "Usage / justification metier", "type": "textarea", "required": False, "value": "travail sur design"},
                            ],
                            "remove": ["ALL"],
                            "replaceBase": True,
                        },
                    }
                ],
            },
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual((custom_fields.get("ai_type_de_materiel") or {}).get("value"), "Ecran")
        self.assertEqual(model._norm((custom_fields.get("ai_specification_modele_souhaite") or {}).get("value")), "27 pouces")
        self.assertNotIn("ai_quantite", custom_fields)

    def test_learned_attestation_schema_drops_uncalled_date_and_duplicate_banque_field(self):
        feedback_samples = [
            {
                "prompt": "Attestation de salaire pour banque",
                "general": {"typeDemande": "Autre", "categorie": "Autre"},
                "details": {
                    "ai_type_d_attestation": "Attestation de salaire",
                    "ai_motif_contexte": "banque",
                    "ai_organisme_destinataire": "banque",
                    "ai_date_souhaitee": "2026-05-21",
                },
                "fieldPlan": {
                    "add": [
                        {"key": "ai_type_d_attestation", "label": "Type d attestation", "type": "text", "required": False, "value": "Attestation de salaire"},
                        {"key": "ai_motif_contexte", "label": "Motif contexte", "type": "text", "required": False, "value": "banque"},
                        {"key": "ai_organisme_destinataire", "label": "Organisme destinataire", "type": "text", "required": False, "value": "banque"},
                        {"key": "ai_date_souhaitee", "label": "Date souhaitee", "type": "date", "required": False, "value": "2026-05-21"},
                    ],
                    "remove": ["ALL"],
                    "replaceBase": True,
                },
            }
        ]

        response = model._build_autre_response(
            {
                "text": "Attestation de salaire pour banque",
                "general": {"typeDemande": "Autre", "categorie": "Autre"},
                "acceptedAutreFeedback": feedback_samples,
            },
            "",
        )
        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}

        self.assertEqual((custom_fields.get("ai_type_d_attestation") or {}).get("value"), "Attestation de salaire")
        self.assertEqual((custom_fields.get("ai_organisme_destinataire") or {}).get("value"), "Banque")
        self.assertNotIn("ai_motif_contexte", custom_fields)
        self.assertNotIn("ai_date_souhaitee", custom_fields)

    def test_parking_zone_extraction_stops_before_duration_and_medical_reason(self):
        prompt = "parking pres de l entree seulement 2 semaines entorse"
        response = model._build_autre_response(
            {"text": prompt, "general": {"typeDemande": "Autre", "categorie": "Autre"}},
            "",
        )

        custom_fields = {field.get("key"): field for field in response.get("custom_fields", [])}
        zone = (custom_fields.get("ai_zone_souhaitee") or {}).get("value", "")

        self.assertEqual(model._norm(zone), model._norm("Pres de l'entree"))


if __name__ == "__main__":
    unittest.main()
