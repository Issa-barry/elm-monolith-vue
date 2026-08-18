<?php

namespace Database\Seeders;

use App\Enums\CategorieTarifaireVehicule;
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
    // Catégorie tarifaire par défaut (cf. App\Enums\CategorieTarifaireVehicule) : "Tricycle" est
    // le seul type classé TRICYCLE d'origine, tous les autres démarrent en AUTRE_VEHICULE —
    // reste modifiable ensuite par l'organisation via TypeVehiculeController.
    private const TYPES = [
        'Tricycle' => CategorieTarifaireVehicule::TRICYCLE,
        'Minibus' => CategorieTarifaireVehicule::AUTRE_VEHICULE,
        'Camionette' => CategorieTarifaireVehicule::AUTRE_VEHICULE,
        'Camion' => CategorieTarifaireVehicule::AUTRE_VEHICULE,
        'Remorque' => CategorieTarifaireVehicule::AUTRE_VEHICULE,
    ];

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::TYPES as $nom => $categorieTarifaire) {
            TypeVehicule::firstOrCreate(
                ['organization_id' => $organizationId, 'nom' => $nom],
                ['is_active' => true, 'categorie_tarifaire' => $categorieTarifaire]
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
