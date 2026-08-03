<?php

namespace Database\Seeders;

use Database\Seeders\Organizations\FelloDemo\FelloDemoCatalogSeeder;
use Database\Seeders\Organizations\FelloDemo\FelloDemoCustomersSeeder;
use Database\Seeders\Organizations\FelloDemo\FelloDemoOrganizationSeeder;
use Database\Seeders\Organizations\FelloDemo\FelloDemoSalesSeeder;
use Database\Seeders\Organizations\FelloDemo\FelloDemoSitesSeeder;
use Database\Seeders\Organizations\FelloDemo\FelloDemoStockSeeder;
use Database\Seeders\Organizations\FelloDemo\FelloDemoUsersSeeder;
use Illuminate\Database\Seeder;

/**
 * Jeu de données de démonstration pour l'organisation "Fello Demo" (vitrine
 * commerciale boutique/POS : PDV, facturation, encaissements, stock,
 * statistiques), totalement indépendant de l'organisation "elm" (Eau la
 * maman). Autonome et idempotent — peut être relancé sans créer de
 * doublons, et fonctionne sur une base déjà migrée, avec ou sans "elm".
 *
 * Lancement :
 *   php artisan db:seed --class=FelloDemoSeeder
 *
 * Volontairement absent de DatabaseSeeder::run() : ne doit pas s'exécuter à
 * chaque migrate:fresh --seed, pour ne pas alourdir le seed par défaut de
 * l'organisation "elm".
 */
class FelloDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FelloDemoOrganizationSeeder::class,
            FelloDemoSitesSeeder::class,
            FelloDemoUsersSeeder::class,
            FelloDemoCatalogSeeder::class,
            FelloDemoStockSeeder::class,
            FelloDemoCustomersSeeder::class,
            FelloDemoSalesSeeder::class,
        ]);
    }
}
