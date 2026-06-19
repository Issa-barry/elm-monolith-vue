<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── Système ───────────────────────────────────────────────────────
            RolesAndPermissionsSeeder::class,   // Organisation, comptes staff, rôles, permissions
            ComptableCommercialeSeeder::class,  // Comptes connectables: comptable + commerciale

            // ── Référentiels ──────────────────────────────────────────────────
            ClientSeeder::class,                // Comptes clients (utilisateurs)
            ClientsInscriptionSeeder::class,    // 2 clients sans compte (lookup inscription)
            PrestatairesSeeder::class,          // Prestataires de service
            SitesSeeder::class,                 // Sites (siège, usine, agences, dépôts)
            UserSitesSeeder::class,             // Rattachement utilisateurs → sites
            AdminEntrepriseSeeder::class,       // Comptes admin_entreprise additionnels (Matoto)
            ProduitsSeeder::class,              // Catalogue produits
            ParametreSeeder::class,             // Paramètres applicatifs

            // ── Module Véhicules ──────────────────────────────────────────────
            TypeVehiculesSeeder::class,         // 3 types par défaut (Camion, Minibus, Tricycle)
            LivreursSeeder::class,              // 22 livreurs (10 externes + 12 internes)
            LivreurComptesSeeder::class,        // Comptes livreurs : 2 actifs + 1 pending
            ProprietairesSeeder::class,         // 4 propriétaires
            EquipesLivraisonSeeder::class,      // 10 equipes : 5 externes + 5 internes (elm)
            VehiculesSeeder::class,             // 10 vehicules : 5 externes + 5 internes

            // ── Module RH ─────────────────────────────────────────────────────
            EmployesSeeder::class,                  // 2 employés (Matoto + Lansanaya) avec contrats CDI

            // ── Paramétrage métier ────────────────────────────────────────────
            DepenseTypesSeeder::class,              // 5 types de dépense par défaut

            // ── Module Commissions ────────────────────────────────────────────
            // CommissionsSeeder::class,           // 6 commissions : EN_ATTENTE / PARTIELLE / VERSÉE
            CommissionLogistiqueSeeder::class,  // 2 commissions logistiques : IMPAYÉ + PAYÉ
        ]);
    }
}
