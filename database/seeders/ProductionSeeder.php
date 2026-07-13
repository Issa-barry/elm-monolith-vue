<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder de mise en production : uniquement les données de référence
 * nécessaires au démarrage, aucune donnée de démonstration (pas de clients,
 * livreurs, véhicules ou transferts fictifs).
 *
 * Les comptes utilisateurs autres que le super_admin sont ajoutés via le
 * flux d'invitation de l'application, jamais par seeder.
 *
 *   php artisan db:seed --class=ProductionSeeder
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Organisation, permissions, rôles (comptes de démo auto-skippés en production)
            RolesAndPermissionsSeeder::class,

            // ── Référentiels ──────────────────────────────────────────────────
            SitesSeeder::class,           // Sites réels (siège, usine, agences, dépôts)
            ProduitsSeeder::class,        // Catalogue produits
            ParametreSeeder::class,       // Paramètres applicatifs par défaut
            TypeVehiculesSeeder::class,   // Types de véhicule par défaut

            // ── Compte réel ───────────────────────────────────────────────────
            SuperAdminSeeder::class,      // Unique compte super_admin de mise en prod
        ]);
    }
}
