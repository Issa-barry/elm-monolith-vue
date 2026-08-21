<?php

namespace Database\Seeders;

use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoCatalogSeeder;
use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoFleetSeeder;
use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoOrganizationSeeder;
use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoSitesSeeder;
use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoStockSeeder;
use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoUsersSeeder;
use Illuminate\Database\Seeder;

/**
 * Organisation de démonstration "Eau La Maman V2 Demo" — seule organisation
 * du projet avec le moteur de commissions V2 explicitement activé, pour
 * permettre les tests E2E du parcours complet V2 (barèmes → partage équipe →
 * vente → encaissement → génération → période → fiche → paiement) sans
 * jamais faire basculer l'organisation "elm" (Legacy) utilisée par toutes
 * les autres specs. Totalement indépendante et idempotente.
 *
 * Lancement :
 *   php artisan db:seed --class=ElmV2DemoSeeder
 *
 * Volontairement absent de DatabaseSeeder::run() (même principe que
 * FelloDemoSeeder) : ne doit pas alourdir le seed par défaut de "elm".
 * Appelé explicitement par `npm run e2e:db:reset` (cf. package.json), après
 * le seed par défaut, pour être disponible dans l'environnement E2E.
 */
class ElmV2DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ElmV2DemoOrganizationSeeder::class,
            ElmV2DemoSitesSeeder::class,
            ElmV2DemoUsersSeeder::class,
            ElmV2DemoCatalogSeeder::class,
            ElmV2DemoFleetSeeder::class,
            ElmV2DemoStockSeeder::class,
        ]);
    }
}
