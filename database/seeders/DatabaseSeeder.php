<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PrestatairesSeeder::class,
            ProprietairesSeeder::class,
            ProduitsSeeder::class,
            ParametreSeeder::class,
        ]);
    }
}
