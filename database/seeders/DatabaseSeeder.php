<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── Système ───────────────────────────────────────────────────────
            RolesAndPermissionsSeeder::class,   // Organisation "elm", rôles, permissions
            ElmDemoAccountsSeeder::class,       // Comptes staff de démo "elm" (Staff@2025) — jamais dans ProductionSeeder

            // ── Référentiels ──────────────────────────────────────────────────
            ClientSeeder::class,                // Comptes clients (utilisateurs)
            ClientsInscriptionSeeder::class,    // 2 clients sans compte (lookup inscription)
            PrestatairesSeeder::class,          // Prestataires de service
            FournisseursSeeder::class,          // Fournisseurs (entité séparée, cf. FournisseurController)
            SitesSeeder::class,                 // Sites (siège, usine, agences, dépôts)
            UserSitesSeeder::class,             // Rattachement utilisateurs → sites
            AdminEntrepriseSeeder::class,       // Comptes admin_entreprise additionnels (Matoto)
            // CategorieDefaultSeeder volontairement absent : "elm" n'a pas encore besoin de
            // catégories (catalogue à 7 produits, cf. ProduitsSeeder). Toujours utilisé par
            // Fello Demo via FelloDemoOrganizationSeeder::seedPourOrganisation().
            OptionCatalogueDefaultSeeder::class, // Options système par défaut (Couleur, Taille, Pointure)
            ProduitTypeDefaultSeeder::class,    // Types de produit par défaut (obligatoire — un produit ne peut exister sans type)
            ProduitsSeeder::class,              // Catalogue produits
            ParametreSeeder::class,             // Paramètres applicatifs

            // ── Module Véhicules ──────────────────────────────────────────────
            TypeVehiculesSeeder::class,         // 5 types par défaut (Tricycle/Minibus/Camionette/Camion/Remorque)
            LivreursSeeder::class,              // 22 livreurs (10 externes + 12 internes)
            LivreurComptesSeeder::class,        // Comptes livreurs : 2 actifs + 1 pending
            ProprietairesSeeder::class,         // 4 propriétaires
            EquipesLivraisonSeeder::class,      // 10 equipes : 5 externes + 5 internes (elm)
            VehiculesSeeder::class,             // 10 vehicules : 5 externes + 5 internes

            // ── Module RH ─────────────────────────────────────────────────────
            EmployesSeeder::class,              // 2 employés (Matoto + Lansanaya) avec contrats CDI

            // ── Paramétrage métier ────────────────────────────────────────────
            DepenseTypesSeeder::class,          // 5 types de dépense par défaut
        ]);
    }
}
