<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ClientSeeder::class,
            PrestatairesSeeder::class,
            LivreursSeeder::class,
            ProprietairesSeeder::class,
            VehiculesSeeder::class,
            SitesSeeder::class,
            ProduitsSeeder::class,
            ParametreSeeder::class,
        ]);
    }
}
