<?php

namespace Database\Seeders;

use App\Enums\CategorieStatut;
use App\Enums\DomaineActivite;
use App\Models\Categorie;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Arborescence de catégories proposée par défaut à toute organisation — adaptée au domaine
 * d'activité choisi à l'installation (cf. InstallationService::install()) quand il est connu,
 * sinon repli sur l'arbre générique historique (ex: seeders qui n'ont pas encore de domaine,
 * comme FelloDemoOrganizationSeeder, ou les appels sans domaine des tests). L'organisation reste
 * entièrement libre de renommer/déplacer/désactiver via le CRUD Catégories — ce n'est qu'une
 * suggestion initiale, pas une contrainte figée. Volontairement mesuré (quelques catégories
 * racines par domaine, pas une taxonomie exhaustive) : un domaine comme "Commerce & Distribution"
 * couvre aussi bien une quincaillerie qu'une boutique de vêtements, inutile d'imposer d'emblée
 * un catalogue pensé pour un seul de ces métiers.
 *
 * Idempotent (firstOrCreate par organisation+nom+parent) — peut être relancé sans dupliquer.
 */
class CategorieDefaultSeeder extends Seeder
{
    /** Repli historique, utilisé quand aucun domaine n'est fourni. @var array<string, array<int, string>> */
    private const ARBRE = [
        'Vêtements' => ['T-shirts', 'Chemises', 'Pantalons', 'Accessoires'],
        'Chaussures' => ['Sneakers', 'Sandales', 'Bottes', 'Autres'],
        'Boissons' => ['Eau', 'Sachet', 'Bouteille'],
        'Matériel' => [],
    ];

    /** @var array<string, array<string, array<int, string>>> */
    private const ARBRE_PAR_DOMAINE = [
        'commerce_distribution' => [
            'Vêtements' => ['T-shirts', 'Chemises', 'Pantalons', 'Accessoires'],
            'Chaussures' => ['Sneakers', 'Sandales', 'Bottes'],
            'Alimentaire & Boissons' => ['Eau', 'Boissons', 'Épicerie'],
            'Quincaillerie' => [],
            'Cosmétique & Hygiène' => [],
            'Électronique' => [],
            "Sachet d'eau" => [],
            "Bouteille d'eau" => [],
        ],
        'industrie_fabrication' => [
            'Matières premières' => [],
            'Consommables' => ['Emballages', "Fournitures d'atelier"],
            'Produits finis' => [],
            'Outillage & Équipement' => [],
            "Sachet d'eau" => [],
            "Bouteille d'eau" => [],
        ],
        'restauration' => [
            'Plats' => ['Entrées', 'Plats principaux', 'Desserts'],
            'Boissons' => ['Fraîches', 'Chaudes'],
            'Ingrédients' => [],
        ],
        'logistique_transport' => [
            'Services de transport' => [],
            'Pièces & consommables véhicules' => ['Pneus', 'Pièces détachées', 'Carburant'],
            'Emballages & manutention' => [],
        ],
        // 'autre' volontairement absent : socle minimal neutre (cf. self::ARBRE_AUTRE) — pas de
        // métier présumé pour ce cas fourre-tout.
    ];

    /** @var array<string, array<int, string>> */
    private const ARBRE_AUTRE = [
        'Divers' => [],
    ];

    public static function seedPourOrganisation(string $organizationId, ?DomaineActivite $domaine = null): void
    {
        $arbre = match (true) {
            $domaine === null => self::ARBRE,
            $domaine === DomaineActivite::AUTRE => self::ARBRE_AUTRE,
            default => self::ARBRE_PAR_DOMAINE[$domaine->value] ?? self::ARBRE,
        };

        foreach ($arbre as $nomParent => $enfants) {
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
        self::seedPourOrganisation($org->id, $org->domaine_activite);

        $this->command->info('✓ Catégories par défaut prêtes pour « elm ».');
    }
}
