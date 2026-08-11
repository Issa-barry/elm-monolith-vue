<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TypeVehicule;
use Illuminate\Database\Seeder;

/**
 * Idempotent (firstOrCreate par organisation+nom) — peut être relancé sans dupliquer. Utilisé
 * aussi bien par le seeder dev/prod (run(), toutes organisations) que par l'installation
 * (seedPourOrganisation(), une seule organisation) — cf. InstallationService.
 */
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

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::TYPES as $type) {
            TypeVehicule::firstOrCreate(
                ['organization_id' => $organizationId, 'nom' => $type['nom']],
                [
                    'capacite_defaut' => $type['capacite_defaut'],
                    'unite_capacite' => 'packs',
                    'is_active' => true,
                ]
            );
        }
    }

    public function run(): void
    {
        foreach (Organization::all() as $org) {
            self::seedPourOrganisation($org->id);
        }
    }
}
