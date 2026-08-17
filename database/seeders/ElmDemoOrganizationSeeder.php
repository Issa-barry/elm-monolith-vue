<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Organisation de référence "elm" (Eau la maman) utilisée par le jeu de données de dev local / CI
 * de tests (cf. DatabaseSeeder) — jamais appelée par le pipeline de déploiement de production, qui
 * ne seede que RolesAndPermissionsSeeder (données globales, sans organisation). Extrait de
 * RolesAndPermissionsSeeder::run() : un seeder de rôles/permissions ne doit pas créer de donnée
 * métier, c'est `/install` (InstallationService) qui a la responsabilité exclusive de créer une
 * organisation, y compris en production réelle "Eau la maman".
 */
class ElmDemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        Organization::firstOrCreate(
            ['slug' => 'elm'],
            ['name' => 'Eau la maman', 'is_active' => true]
        );
    }
}
