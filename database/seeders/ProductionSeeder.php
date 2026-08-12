<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder de mise en production : uniquement les données de référence
 * nécessaires au démarrage, aucune donnée de démonstration (pas de clients,
 * livreurs, véhicules ou transferts fictifs).
 *
 * Le compte super_admin n'est PAS créé ici — voir `php artisan app:install`
 * (InstallApp), qui s'exécute après ce seeder : mot de passe saisi en masqué,
 * jamais affiché en clair (contrairement à l'ancien SuperAdminSeeder, retiré).
 * Les autres comptes utilisateurs sont ajoutés via le flux d'invitation de
 * l'application, jamais par seeder.
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *   php artisan app:install
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Organisation "elm", permissions, rôles — aucun compte de démo (ce seeder n'appelle
            // jamais ElmDemoAccountsSeeder, quelle que soit la valeur d'APP_ENV).
            RolesAndPermissionsSeeder::class,

            // ── Référentiels ──────────────────────────────────────────────────
            SitesSeeder::class,           // Sites réels (siège, usine, agences, dépôts)
            // CategorieDefaultSeeder volontairement absent : "elm" n'a pas encore besoin de
            // catégories (catalogue à 7 produits, cf. ProduitsSeeder).
            OptionCatalogueDefaultSeeder::class, // Options système par défaut (Couleur, Taille, Pointure)
            ProduitTypeDefaultSeeder::class, // Types de produit par défaut (obligatoire — un produit ne peut exister sans type)
            ProduitsSeeder::class,        // Catalogue produits
            ParametreSeeder::class,       // Paramètres applicatifs par défaut
            TypeVehiculesSeeder::class,   // Types de véhicule par défaut
        ]);
    }
}
