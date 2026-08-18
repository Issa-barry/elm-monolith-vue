<?php

namespace Database\Seeders;

use App\Enums\ProduitTypeStatut;
use App\Models\Organization;
use App\Models\ProduitType;
use Illuminate\Database\Seeder;

/**
 * Types de produit provisionnés par défaut à la création de TOUTE organisation — contrairement
 * à CategorieDefaultSeeder/OptionCatalogueDefaultSeeder (optionnels, cochés à l'installation),
 * celui-ci est OBLIGATOIRE et inconditionnel : un produit ne peut pas exister sans type
 * (produits.produit_type_id est requis), donc une organisation sans aucun type serait bloquée
 * dès sa première tentative de création de produit. Reproduit exactement les capacités de
 * l'ancien enum figé App\Enums\ProduitType (avant refonte CRUD) pour préserver le comportement
 * historique — l'organisation reste ensuite entièrement libre de renommer/modifier/désactiver
 * ces types ou d'en créer d'autres via le CRUD Types.
 *
 * Idempotent (firstOrCreate par organisation+code) — peut être relancé sans dupliquer.
 */
class ProduitTypeDefaultSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    private const TYPES = [
        [
            'code' => 'materiel',
            'nom' => 'Matériel',
            'gere_stock' => true,
            'vendable' => false,
            'achetable' => true,
            'prix_achat_requis' => true,
            'prix_usine_requis' => false,
            'prix_vente_requis' => false,
            'champ_prix_reference' => null,
            'position' => 0,
        ],
        [
            'code' => 'service',
            'nom' => 'Service',
            'gere_stock' => false,
            'vendable' => false,
            'achetable' => false,
            'prix_achat_requis' => false,
            'prix_usine_requis' => false,
            'prix_vente_requis' => false,
            'champ_prix_reference' => null,
            'position' => 1,
        ],
        [
            'code' => 'fabricable',
            'nom' => 'Fabricable',
            'gere_stock' => true,
            'vendable' => true,
            'achetable' => false,
            'prix_achat_requis' => false,
            'prix_usine_requis' => true,
            'prix_vente_requis' => true,
            'champ_prix_reference' => 'prix_usine',
            'position' => 2,
        ],
        [
            'code' => 'achat_vente',
            'nom' => 'Achat / Vente',
            'gere_stock' => true,
            'vendable' => true,
            'achetable' => true,
            'prix_achat_requis' => true,
            'prix_usine_requis' => false,
            'prix_vente_requis' => true,
            'champ_prix_reference' => 'prix_achat',
            'position' => 3,
        ],
        [
            // Acheté et consommé pour fabriquer un autre produit (matière première, emballage,
            // bouchon, étiquette...) — jamais vendu tel quel, distinct de `materiel` (acheté pour
            // les besoins de l'entreprise, hors production) même si le comportement technique est
            // proche : la distinction est l'intention métier, pas le comportement.
            // Exemple : Matière de production (bouchon, préforme) → fabrication → Fabricable
            // (pack d'eau) → vente.
            'code' => 'matiere_production',
            'nom' => 'Matière de production',
            'gere_stock' => true,
            'vendable' => false,
            'achetable' => true,
            'prix_achat_requis' => true,
            'prix_usine_requis' => false,
            'prix_vente_requis' => false,
            'champ_prix_reference' => 'prix_achat',
            'position' => 4,
        ],
    ];

    public static function seedPourOrganisation(string $organizationId): void
    {
        foreach (self::TYPES as $donnees) {
            ProduitType::firstOrCreate(
                ['organization_id' => $organizationId, 'code' => $donnees['code']],
                [...$donnees, 'statut' => ProduitTypeStatut::ACTIF]
            );
        }
    }

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        self::seedPourOrganisation($org->id);

        $this->command->info('✓ Types de produit par défaut (Matériel, Service, Fabricable, Achat/Vente, Matière de production) prêts pour « elm ».');
    }
}
