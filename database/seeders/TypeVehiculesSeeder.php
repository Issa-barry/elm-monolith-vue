<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TypeVehicule;
use Illuminate\Database\Seeder;

class TypeVehiculesSeeder extends Seeder
{
    // Un seul type par famille de véhicule : capacite_defaut n'est qu'un pré-remplissage,
    // chaque véhicule garde sa propre capacité réelle dans vehicules.capacite_packs
    // (cf. VehiculeForm.vue et VehiculeController::index fallback).
    private const TYPES = [
        ['nom' => 'Tricycle',    'capacite_defaut' => 80],
        ['nom' => 'Minibus',     'capacite_defaut' => 200],
        ['nom' => 'Camionette',  'capacite_defaut' => 450],
        ['nom' => 'Camion',      'capacite_defaut' => 1700],
        ['nom' => 'Remorque',    'capacite_defaut' => 2650],
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
