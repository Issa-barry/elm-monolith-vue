<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Models\Organization;
use Database\Seeders\CategorieDefaultSeeder;
use Database\Seeders\OptionCatalogueDefaultSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Organisation de démonstration "Fello Demo" — vitrine commerciale boutique
 * / POS, totalement indépendante de l'organisation "elm" (Eau la maman).
 *
 * Ne jamais utiliser Organization::first() : toujours retrouver ou créer via
 * le slug 'fello-demo', comme tous les seeders du projet le font pour 'elm'.
 */
class FelloDemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'fello-demo'],
            ['name' => 'Fello Demo', 'is_active' => true]
        );

        // Les rôles Spatie sont globaux (non rattachés à une organisation).
        // On s'assure seulement qu'ils existent, sans toucher à leurs
        // permissions si RolesAndPermissionsSeeder les a déjà définies —
        // ne jamais réassigner de permissions ici pour ne pas modifier le
        // comportement des rôles déjà utilisés par l'organisation "elm".
        foreach (['admin_entreprise', 'commerciale', 'client'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Catalogues de référence par défaut — FelloDemoCatalogSeeder s'appuie dessus
        // (catégories Vêtements/Chaussures) au lieu de recréer sa propre arborescence.
        CategorieDefaultSeeder::seedPourOrganisation($org->id);
        OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);

        $this->command->info("✓ Organisation « Fello Demo » prête (slug: fello-demo, id: {$org->id}).");
    }
}
