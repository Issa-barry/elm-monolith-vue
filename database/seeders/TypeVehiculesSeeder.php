<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TypeVehicule;
use Illuminate\Database\Seeder;

class TypeVehiculesSeeder extends Seeder
{
    private const TYPES = [
        ['nom' => 'Camion',       'capacite_defaut' => 1000],
        ['nom' => 'Minibus 200',  'capacite_defaut' => 200],
        ['nom' => 'Minibus 270',  'capacite_defaut' => 270],
        ['nom' => 'Tricycle moto', 'capacite_defaut' => 150],
    ];

    public function run(): void
    {
        $orgs = Organization::all();

        foreach ($orgs as $org) {
            foreach (self::TYPES as $type) {
                TypeVehicule::firstOrCreate(
                    ['organization_id' => $org->id, 'nom' => $type['nom']],
                    [
                        'capacite_defaut' => $type['capacite_defaut'],
                        'unite_capacite' => 'packs',
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
