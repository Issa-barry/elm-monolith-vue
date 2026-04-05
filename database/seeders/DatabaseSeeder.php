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

            // ── Référentiels ──────────────────────────────────────────────────
            ClientSeeder::class,                // Comptes clients (utilisateurs)
            PrestatairesSeeder::class,          // Prestataires de service
            SitesSeeder::class,                 // Sites (siège, usine, agences, dépôts)
            ProduitsSeeder::class,              // Catalogue produits
            ParametreSeeder::class,             // Paramètres applicatifs

            // ── Module Véhicules ──────────────────────────────────────────────
            LivreursSeeder::class,              // 10 livreurs
            ProprietairesSeeder::class,         // 4 propriétaires
            EquipesLivraisonSeeder::class,      // 5 équipes (membres + taux)
            VehiculesSeeder::class,             // 7 véhicules (avec équipe assignée)

            // ── Module Commissions ────────────────────────────────────────────
            CommissionsSeeder::class,           // 6 commissions : EN_ATTENTE / PARTIELLE / VERSÉE
        ]);
    }
}
