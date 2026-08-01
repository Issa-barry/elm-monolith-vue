<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TypeVehicule;
use Illuminate\Database\Seeder;

class TypeVehiculesSeeder extends Seeder
{
    private const TYPES = [
        ['nom' => 'Tricycle-70',    'capacite_defaut' => 70],
        ['nom' => 'Tricycle-75',    'capacite_defaut' => 75],
        ['nom' => 'Tricycle-80',    'capacite_defaut' => 80],
        ['nom' => 'Tricycle-90',    'capacite_defaut' => 90],
        ['nom' => 'Tricycle-100',   'capacite_defaut' => 100],
        ['nom' => 'Minibus-160',    'capacite_defaut' => 160],
        ['nom' => 'Minibus-200',    'capacite_defaut' => 200],
        ['nom' => 'Minibus-270',    'capacite_defaut' => 270],
        ['nom' => 'CAMIONETTE-450', 'capacite_defaut' => 450],
        ['nom' => 'CAMION-1500',    'capacite_defaut' => 1500],
        ['nom' => 'CAMION-1700',    'capacite_defaut' => 1700],
        ['nom' => 'CAMION-1900',    'capacite_defaut' => 1900],
        ['nom' => 'REMORQUE-2650',  'capacite_defaut' => 2650],
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
