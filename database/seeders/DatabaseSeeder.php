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
            ClientsInscriptionSeeder::class,    // 2 clients sans compte (lookup inscription)
            PrestatairesSeeder::class,          // Prestataires de service
            SitesSeeder::class,                 // Sites (siège, usine, agences, dépôts)
            UserSitesSeeder::class,             // Rattachement utilisateurs → sites
            ProduitsSeeder::class,              // Catalogue produits
            ParametreSeeder::class,             // Paramètres applicatifs
            ParametreSeeder::class,             // Paramètres applicatifs
 
            // ── Module Véhicules ──────────────────────────────────────────────
            LivreursSeeder::class,              // 16 livreurs (10 externes + 6 internes)
            ProprietairesSeeder::class,         // 4 propriétaires
            EquipesLivraisonSeeder::class,      // 7 equipes : 4 externes + 3 internes (elm)
            VehiculesSeeder::class,             // 6 vehicules : 3 externes + 3 internes (elm-1/2/3)

            // ── Module Commissions ────────────────────────────────────────────
            // CommissionsSeeder::class,           // 6 commissions : EN_ATTENTE / PARTIELLE / VERSÉE
        ]);
    }
}
