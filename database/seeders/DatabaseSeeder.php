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
            ProduitsSeeder::class,              // Catalogue produits
            ParametreSeeder::class,             // Paramètres applicatifs

            // ── Module Véhicules ──────────────────────────────────────────────
            LivreursSeeder::class,              // 16 livreurs (10 externes + 6 internes)
            ProprietairesSeeder::class,         // 4 propriétaires
            EquipesLivraisonSeeder::class,      // 9 equipes : 5 externes + 4 internes (elm)
            VehiculesSeeder::class,             // 9 vehicules : 5 externes + 4 internes

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
