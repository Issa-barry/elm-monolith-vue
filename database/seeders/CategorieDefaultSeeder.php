<?php

namespace Database\Seeders;

use App\Enums\CategorieStatut;
use App\Models\Categorie;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Arborescence de catégories proposée par défaut à toute organisation — point de départ
 * générique couvrant les profils de tenants déjà présents dans le projet (eau/boissons pour
 * "elm", habillement/chaussures pour "Fello Demo"). L'organisation reste entièrement libre
 * de renommer/déplacer/désactiver via le CRUD Catégories — ce n'est qu'une suggestion initiale,
 * pas une contrainte figée.
 *
 * Idempotent (firstOrCreate par organisation+nom+parent) — peut être relancé sans dupliquer.
 */
class CategorieDefaultSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private const ARBRE = [
        'Vêtements' => ['T-shirts', 'Chemises', 'Pantalons', 'Accessoires'],
        'Chaussures' => ['Sneakers', 'Sandales', 'Bottes', 'Autres'],
        'Boissons' => ['Eau', 'Sachet', 'Bouteille'],
        'Matériel' => [],
    ];

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::ARBRE as $nomParent => $enfants) {
            $parent = Categorie::firstOrCreate(
                ['organization_id' => $organizationId, 'nom' => $nomParent, 'parent_id' => null],
                ['statut' => CategorieStatut::ACTIF]
            );

            foreach ($enfants as $nomEnfant) {
                Categorie::firstOrCreate(
                    ['organization_id' => $organizationId, 'nom' => $nomEnfant, 'parent_id' => $parent->id],
                    ['statut' => CategorieStatut::ACTIF]
                );
            }
        }
    }

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        self::seedPourOrganisation($org->id);

        $this->command->info('✓ Catégories par défaut (Vêtements, Chaussures, Boissons, Matériel) prêtes pour « elm ».');
    }
}
