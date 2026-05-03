<?php

namespace Database\Seeders;

use App\Models\DepenseType;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Types de dépense par défaut.
 *
 * | Code        | Libellé              | Vehicle | Comment | Sort |
 * |-------------|----------------------|---------|---------|------|
 * | carburant   | Carburant            | true    | false   | 10   |
 * | reparation  | Réparation           | true    | true    | 20   |
 * | bouffe      | Restauration         | false   | false   | 30   |
 * | deplacement | Déplacement          | false   | false   | 40   |
 * | autre       | Autre                | false   | true    | 99   |
 */
class DepenseTypesSeeder extends Seeder
{
    private const TYPES = [
        [
            'code' => 'carburant',
            'libelle' => 'Carburant',
            'description' => 'Achat de carburant pour les véhicules.',
            'requires_vehicle' => true,
            'requires_comment' => false,
            'sort_order' => 10,
        ],
        [
            'code' => 'reparation',
            'libelle' => 'Réparation',
            'description' => 'Réparation et entretien des véhicules.',
            'requires_vehicle' => true,
            'requires_comment' => true,
            'sort_order' => 20,
        ],
        [
            'code' => 'bouffe',
            'libelle' => 'Restauration',
            'description' => 'Repas et restauration du personnel.',
            'requires_vehicle' => false,
            'requires_comment' => false,
            'sort_order' => 30,
        ],
        [
            'code' => 'deplacement',
            'libelle' => 'Déplacement',
            'description' => 'Frais de déplacement et transport.',
            'requires_vehicle' => false,
            'requires_comment' => false,
            'sort_order' => 40,
        ],
        [
            'code' => 'autre',
            'libelle' => 'Autre',
            'description' => 'Toute dépense ne rentrant pas dans les catégories ci-dessus.',
            'requires_vehicle' => false,
            'requires_comment' => true,
            'sort_order' => 99,
        ],
    ];

    public function run(): void
    {
        $organizations = Organization::all();

        foreach ($organizations as $org) {
            foreach (self::TYPES as $type) {
                DepenseType::updateOrCreate(
                    ['organization_id' => $org->id, 'code' => $type['code']],
                    [...$type, 'organization_id' => $org->id, 'is_active' => true]
                );
            }
        }
    }
}
