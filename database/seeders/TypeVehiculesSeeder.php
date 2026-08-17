<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\TypeVehicule;
use Illuminate\Database\Seeder;

/**
 * Idempotent (firstOrCreate par organisation+nom) — peut être relancé sans dupliquer. Utilisé
 * aussi bien par le seeder dev/prod (run(), toutes organisations) que par l'installation
 * (seedPourOrganisation(), une seule organisation) — cf. InstallationService.
 *
 * Classification pure : aucune capacité (décision produit du 17/08/2026) — chaque véhicule
 * porte sa propre capacité réelle, voir VehiculeCapaciteService.
 */
class TypeVehiculesSeeder extends Seeder
{
    private const TYPES = ['Tricycle', 'Minibus', 'Camionette', 'Camion', 'Remorque'];

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::TYPES as $nom) {
            TypeVehicule::firstOrCreate(
                ['organization_id' => $organizationId, 'nom' => $nom],
                ['is_active' => true]
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
