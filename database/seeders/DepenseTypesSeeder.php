<?php

namespace Database\Seeders;

use App\Models\DepenseType;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class DepenseTypesSeeder extends Seeder
{
    private const TYPES = [
        // ── Véhicule ─────────────────────────────────────────────────────────
        [
            'code' => 'carburant_vehicule',
            'libelle' => 'Carburant véhicule',
            'description' => 'Achat de carburant pour les véhicules.',
            'categorie' => 'vehicule',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'reparation_vehicule',
            'libelle' => 'Réparation véhicule',
            'description' => 'Réparation et entretien des véhicules.',
            'categorie' => 'vehicule',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],
        [
            'code' => 'achat_pneu',
            'libelle' => 'Achat pneu',
            'description' => 'Achat de pneus pour les véhicules.',
            'categorie' => 'vehicule',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],
        [
            'code' => 'accident_vehicule',
            'libelle' => 'Accident véhicule',
            'description' => 'Frais liés à un accident de véhicule.',
            'categorie' => 'vehicule',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],
        [
            'code' => 'vidange',
            'libelle' => 'Vidange',
            'description' => 'Vidange moteur du véhicule.',
            'categorie' => 'vehicule',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'entretien',
            'libelle' => 'Entretien',
            'description' => 'Entretien général du véhicule.',
            'categorie' => 'vehicule',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],

        // ── Propriétaire ─────────────────────────────────────────────────────
        [
            'code' => 'avance_commission_proprio',
            'libelle' => 'Avance commission propriétaire',
            'description' => 'Avance versée sur la commission mensuelle du propriétaire.',
            'categorie' => 'proprietaire',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'retenue_proprio',
            'libelle' => 'Retenue propriétaire',
            'description' => 'Retenue déduite de la commission du propriétaire.',
            'categorie' => 'proprietaire',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'dette_proprio',
            'libelle' => 'Dette propriétaire',
            'description' => 'Remboursement de dette déduit de la commission.',
            'categorie' => 'proprietaire',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],

        // ── Livreur ──────────────────────────────────────────────────────────
        [
            'code' => 'avance_livreur',
            'libelle' => 'Avance livreur',
            'description' => 'Avance versée au livreur, déduite de sa prochaine commission.',
            'categorie' => 'livreur',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'commission_livreur',
            'libelle' => 'Commission livreur',
            'description' => 'Commission quinzainale versée au livreur.',
            'categorie' => 'livreur',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'achat_personnel_livreur',
            'libelle' => 'Achat personnel livreur',
            'description' => 'Achat personnel effectué pour le compte du livreur.',
            'categorie' => 'livreur',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'accident_livreur',
            'libelle' => 'Accident livreur',
            'description' => 'Frais liés à un accident du livreur.',
            'categorie' => 'livreur',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],
        [
            'code' => 'frais_medicaux_livreur',
            'libelle' => 'Frais médicaux',
            'description' => 'Prise en charge frais médicaux du livreur.',
            'categorie' => 'livreur',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],

        // ── Salarié ──────────────────────────────────────────────────────────
        [
            'code' => 'avance_salaire',
            'libelle' => 'Avance salaire',
            'description' => 'Avance versée au salarié, déduite du salaire du mois.',
            'categorie' => 'employe',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => 'avance',
        ],
        [
            'code' => 'indemnite_employe',
            'libelle' => 'Indemnité employé',
            'description' => 'Indemnité versée au salarié (transport, repas, logement…).',
            'categorie' => 'employe',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => 'prime',
        ],
        [
            'code' => 'retenue_salaire',
            'libelle' => 'Retenue salaire',
            'description' => 'Retenue disciplinaire ou autre, déduite du salaire net.',
            'categorie' => 'employe',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => 'retenue',
        ],
        [
            'code' => 'achat_personnel_employe',
            'libelle' => 'Achat personnel employé',
            'description' => 'Achat personnel effectué pour le compte du salarié.',
            'categorie' => 'employe',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],

        // ── Dépense interne ──────────────────────────────────────────────────
        [
            'code' => 'electricite',
            'libelle' => 'Électricité',
            'description' => 'Facture d\'électricité de l\'agence.',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],
        [
            'code' => 'restauration',
            'libelle' => 'Restauration',
            'description' => 'Repas et restauration du personnel.',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'carburant_groupe',
            'libelle' => 'Carburant groupe électrogène',
            'description' => 'Achat de carburant pour le groupe électrogène.',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'transport_interne',
            'libelle' => 'Transport interne',
            'description' => 'Frais de transport interne à l\'agence.',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
        [
            'code' => 'achat_materiel',
            'libelle' => 'Achat matériel',
            'description' => 'Achat de matériel ou fournitures pour l\'agence.',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => true,
            'type_paie' => null,
        ],
        [
            'code' => 'autre_interne',
            'libelle' => 'Autre interne',
            'description' => 'Toute dépense interne ne rentrant pas dans les catégories ci-dessus.',
            'categorie' => 'interne',
            'commentaire_obligatoire' => true,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
        ],
    ];

    public function run(): void
    {
        $organizations = Organization::all();

        foreach ($organizations as $org) {
            foreach (self::TYPES as $type) {
                DepenseType::updateOrCreate(
                    ['organization_id' => $org->id, 'code' => $type['code']],
                    [...$type, 'organization_id' => $org->id, 'is_active' => true]
                );
            }
        }
    }
}
