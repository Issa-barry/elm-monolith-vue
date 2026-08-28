<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Couvre ProduitService::validerPrixSelonType() (règle prix_vente > coût de référence) et la
 * cascade de cohérence multi-variantes lors d'un changement de type — cf. mémoire projet
 * "Prix produit & marge — spec cible".
 */
class ProduitServicePrixTest extends TestCase
{
    use RefreshDatabase;

    private ProduitService $service;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProduitService::class);
        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
    }

    /** Raccourci 'type' => 'materiel'|'service'|'fabricable'|'achat_vente' résolu en produit_type_id. */
    private function creer(array $overrides): Produit
    {
        if (array_key_exists('type', $overrides)) {
            $overrides['produit_type_id'] = ProduitType::where('organization_id', $this->org->id)
                ->where('code', $overrides['type'])
                ->value('id');
            unset($overrides['type']);
        }

        return $this->service->creer(array_merge([
            'organization_id' => $this->org->id,
            'nom' => 'Produit test',
            'statut' => 'actif',
        ], $overrides));
    }

    // ── FABRICABLE ────────────────────────────────────────────────────────────

    /** Tarifs par nature de client — désormais obligatoires pour fabricable, cf. section dédiée
     * plus bas. Valeurs neutres réutilisées dans les tests de marge usine/tricycle ci-dessous,
     * qui ne portent pas sur ces champs. */
    private const PRIX_NATURE_NEUTRES = [
        'prix_externe' => 1,
        'prix_revendeur' => 1,
        'prix_distributeur' => 1,
    ];

    public function test_fabricable_accepte_prix_vente_superieur_au_prix_usine(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_usine_tricycle' => 5000,
            'prix_vente' => 6000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        $this->assertSame(6000, $produit->variantes->first()->prix_vente);
    }

    public function test_fabricable_refuse_prix_vente_egal_au_prix_usine(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 18000,
            'prix_usine_tricycle' => 10000,
            'prix_vente' => 18000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);
    }

    public function test_fabricable_refuse_prix_vente_inferieur_au_prix_usine(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 18000,
            'prix_usine_tricycle' => 10000,
            'prix_vente' => 17999,
            ...self::PRIX_NATURE_NEUTRES,
        ]);
    }

    public function test_fabricable_refuse_si_prix_usine_absent(): void
    {
        try {
            $this->creer(['type' => 'fabricable', 'prix_vente' => 6000, ...self::PRIX_NATURE_NEUTRES]);
            $this->fail('ValidationException attendue.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('produit_type_id', $e->errors());
        }
    }

    // ── Tarif tricycle : contrôlé indépendamment du tarif "autres véhicules" ────

    public function test_fabricable_accepte_prix_usine_tricycle_valide(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_usine_tricycle' => 5050,
            'prix_vente' => 6000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        $this->assertSame(5050, $produit->variantes->first()->prix_usine_tricycle);
    }

    public function test_fabricable_refuse_si_prix_usine_tricycle_egal_au_prix_vente(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_usine_tricycle' => 6000,
            'prix_vente' => 6000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);
    }

    public function test_fabricable_refuse_si_prix_usine_tricycle_superieur_au_prix_vente(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_usine_tricycle' => 6100,
            'prix_vente' => 6000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);
    }

    /** Le tarif standard valide ne doit jamais masquer une marge tricycle invalide. */
    public function test_fabricable_refuse_marge_tricycle_invalide_meme_si_marge_standard_valide(): void
    {
        try {
            $this->creer([
                'type' => 'fabricable',
                'prix_usine' => 5100,
                'prix_usine_tricycle' => 6000,
                'prix_vente' => 6000,
                ...self::PRIX_NATURE_NEUTRES,
            ]);
            $this->fail('ValidationException attendue : marge tricycle nulle.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prix_vente', $e->errors());
        }
    }

    public function test_fabricable_refuse_si_prix_usine_tricycle_absent(): void
    {
        // Décision métier : les deux tarifs sont deux décisions distinctes, jamais l'un déduit
        // de l'autre — dès que prix_usine est requis, prix_usine_tricycle l'est tout autant
        // (cf. ProduitType::requiredPrices()), aucun repli implicite autorisé.
        try {
            $this->creer([
                'type' => 'fabricable',
                'prix_usine' => 5100,
                'prix_vente' => 6000,
                ...self::PRIX_NATURE_NEUTRES,
            ]);
            $this->fail('ValidationException attendue : prix_usine_tricycle absent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('produit_type_id', $e->errors());
        }
    }

    public function test_achat_vente_nimpose_aucune_regle_sur_prix_usine_tricycle(): void
    {
        // champPrixReference() = 'prix_achat' pour ACHAT_VENTE : prix_usine_tricycle n'a pas de
        // sens ici, jamais forcé même si renseigné à une valeur incohérente.
        $produit = $this->creer([
            'type' => 'achat_vente',
            'prix_achat' => 100000,
            'prix_usine_tricycle' => 999999,
            'prix_vente' => 120000,
        ]);

        $this->assertSame(120000, $produit->variantes->first()->prix_vente);
    }

    public function test_deux_variantes_ont_des_tarifs_tricycle_totalement_independants(): void
    {
        $produit = $this->service->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Eau en bouteille',
            'produit_type_id' => $this->typeId('fabricable'),
            'statut' => 'actif',
            'prix_usine' => 15000,
            'prix_usine_tricycle' => 14700,
            'prix_vente' => 17000,
            ...self::PRIX_NATURE_NEUTRES,
            'options' => [
                ['nom' => 'Contenance', 'valeurs' => ['350ml', '500ml']],
            ],
        ])->fresh(['variantes']);

        $variante350 = $produit->variantes->first();
        $variante500 = $produit->variantes->last();

        $variante500->update(['prix_usine' => 18000, 'prix_usine_tricycle' => 17500, 'prix_vente' => 20000]);

        $variante350->refresh();
        $variante500->refresh();

        $this->assertSame(14700, $variante350->prix_usine_tricycle);
        $this->assertSame(17500, $variante500->prix_usine_tricycle);
    }

    // ── Tarifs par nature de client (prix_externe/prix_revendeur/prix_distributeur) ─────────
    // Réservés au type fabricable, cf. ProduitService::nettoyerPrixNatureSiNonFabricable().

    public function test_fabricable_persiste_les_prix_par_nature_de_client(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_usine_tricycle' => 5000,
            'prix_vente' => 6000,
            'prix_externe' => 5200,
            'prix_revendeur' => 5800,
            'prix_distributeur' => 5500,
        ]);

        $variante = $produit->variantes->first();
        $this->assertSame(5200, $variante->prix_externe);
        $this->assertSame(5800, $variante->prix_revendeur);
        $this->assertSame(5500, $variante->prix_distributeur);
    }

    public function test_fabricable_refuse_si_un_prix_par_nature_est_absent(): void
    {
        try {
            $this->creer([
                'type' => 'fabricable',
                'prix_usine' => 5100,
                'prix_usine_tricycle' => 5000,
                'prix_vente' => 6000,
                'prix_externe' => 5200,
                'prix_revendeur' => 5800,
                // prix_distributeur volontairement absent
            ]);
            $this->fail('ValidationException attendue : prix_distributeur absent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('produit_type_id', $e->errors());
            $this->assertStringContainsString('distributeur', $e->errors()['produit_type_id'][0]);
        }
    }

    public function test_non_fabricable_ignore_les_prix_par_nature_de_client_meme_si_soumis(): void
    {
        $produit = $this->creer([
            'type' => 'achat_vente',
            'prix_achat' => 100000,
            'prix_vente' => 120000,
            'prix_externe' => 999,
            'prix_revendeur' => 999,
            'prix_distributeur' => 999,
        ]);

        $variante = $produit->variantes->first();
        $this->assertNull($variante->prix_externe);
        $this->assertNull($variante->prix_revendeur);
        $this->assertNull($variante->prix_distributeur);
    }

    public function test_mettre_a_jour_simple_annule_les_prix_nature_quand_le_type_change_vers_non_fabricable(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_usine_tricycle' => 5000,
            'prix_vente' => 6000,
            'prix_externe' => 5200,
            'prix_revendeur' => 1,
            'prix_distributeur' => 1,
        ]);

        $produit = $this->service->mettreAJourSimple($produit, [
            'produit_type_id' => $this->typeId('achat_vente'),
            'nom' => $produit->nom,
            'statut' => 'actif',
            'prix_achat' => 100000,
            'prix_vente' => 120000,
            'prix_externe' => 5200,
        ]);

        $this->assertNull($produit->variantes->first()->prix_externe);
    }

    // ── ACHAT_VENTE ───────────────────────────────────────────────────────────

    public function test_achat_vente_accepte_prix_vente_superieur_au_prix_achat(): void
    {
        $produit = $this->creer([
            'type' => 'achat_vente',
            'prix_achat' => 100000,
            'prix_vente' => 120000,
        ]);

        $this->assertSame(120000, $produit->variantes->first()->prix_vente);
    }

    public function test_achat_vente_refuse_prix_vente_egal_au_prix_achat(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'achat_vente',
            'prix_achat' => 100000,
            'prix_vente' => 100000,
        ]);
    }

    public function test_achat_vente_refuse_prix_vente_inferieur_au_prix_achat(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'achat_vente',
            'prix_achat' => 100000,
            'prix_vente' => 90000,
        ]);
    }

    public function test_achat_vente_refuse_si_prix_vente_absent(): void
    {
        try {
            $this->creer(['type' => 'achat_vente', 'prix_achat' => 100000]);
            $this->fail('ValidationException attendue.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('produit_type_id', $e->errors());
        }
    }

    // ── Non-régression MATERIEL / SERVICE ────────────────────────────────────

    public function test_materiel_nimpose_aucune_relation_prix_vente_prix_achat(): void
    {
        // MATERIEL n'est pas vendable (requiredPrices() = ['prix_achat'] seul) : prix_vente
        // n'est ni requis ni comparé, même absent ou incohérent.
        $produit = $this->creer(['type' => 'materiel', 'prix_achat' => 1000]);

        $this->assertSame(1000, $produit->variantes->first()->prix_achat);
    }

    public function test_service_naccepte_aucun_prix_requis(): void
    {
        $produit = $this->creer(['type' => 'service']);

        $this->assertNotNull($produit->id);
    }

    // ── Update partiel : état effectif = persisté + payload ─────────────────

    public function test_update_partiel_prix_vente_seul_est_valide_contre_le_prix_usine_persiste(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable', 'prix_usine' => 18000, 'prix_usine_tricycle' => 17000, 'prix_vente' => 20000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        // PATCH ne renvoie que prix_vente ; prix_usine (18000) doit être récupéré depuis la
        // variante persistée pour la comparaison — pas traité comme absent.
        $this->expectException(ValidationException::class);

        $this->service->mettreAJourSimple($produit, [
            'type' => 'fabricable',
            'prix_vente' => 17000,
        ]);
    }

    public function test_update_partiel_prix_usine_seul_est_valide_contre_le_prix_vente_persiste(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable', 'prix_usine' => 5100, 'prix_usine_tricycle' => 5000, 'prix_vente' => 6000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        // PATCH ne renvoie que prix_usine ; prix_vente (6000) doit être récupéré depuis la
        // variante persistée. 6100 > 6000 => incohérent.
        $this->expectException(ValidationException::class);

        $this->service->mettreAJourSimple($produit, [
            'type' => 'fabricable',
            'prix_usine' => 6100,
        ]);
    }

    public function test_update_partiel_sans_toucher_au_prix_reste_valide(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable', 'prix_usine' => 5100, 'prix_usine_tricycle' => 5000, 'prix_vente' => 6000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        $produit = $this->service->mettreAJourSimple($produit, ['nom' => 'Nouveau nom']);

        $this->assertSame('Nouveau nom', $produit->nom);
        $this->assertSame(6000, $produit->variantes->first()->prix_vente);
    }

    // ── Changement de type : produit à variante unique ───────────────────────

    private function typeId(string $code): string
    {
        return ProduitType::where('organization_id', $this->org->id)->where('code', $code)->value('id');
    }

    public function test_changement_de_type_accepte_si_la_variante_unique_reste_coherente(): void
    {
        $produit = $this->creer(['type' => 'achat_vente', 'prix_achat' => 100000, 'prix_vente' => 120000]);

        $produit = $this->service->mettreAJourSimple($produit, [
            'produit_type_id' => $this->typeId('fabricable'),
            'prix_usine' => 100000,
            'prix_usine_tricycle' => 90000,
            'prix_vente' => 120000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        $this->assertSame($this->typeId('fabricable'), $produit->produit_type_id);
    }

    // ── Changement de type : produit multi-variantes (scénario critique) ────

    /** Produit à 2 variantes (Couleur: Noir/Blanc) créé achat_vente, prix_achat=8000/prix_vente=10000 sur les deux. */
    private function creerProduitMultiVariantes(): Produit
    {
        return $this->service->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit multi-variantes',
            'produit_type_id' => $this->typeId('achat_vente'),
            'statut' => 'actif',
            'prix_achat' => 8000,
            'prix_vente' => 10000,
            'options' => [
                ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
            ],
        ])->fresh(['variantes']);
    }

    public function test_changement_de_type_est_refuse_si_une_variante_non_touchee_devient_incoherente(): void
    {
        // Produit à options (Couleur: Noir/Blanc) : AUCUNE des deux variantes générées n'est
        // "is_default" (cf. test_store_avec_options_genere_les_variantes), donc le formulaire
        // principal — qui n'envoie que le type + des prix "globaux", jamais une variante en
        // particulier — ne touche RÉELLEMENT aucune des deux. Elles ont prix_achat=8000/
        // prix_vente=10000/prix_usine=null/prix_usine_tricycle=null : valide pour ACHAT_VENTE,
        // mais FABRICABLE exige les deux (cf. requiredPrices()), absents sur les deux. Le
        // payload fournit ici les deux tarifs (effective-data du formulaire principal cohérente)
        // pour isoler le test sur le vrai scénario visé : la cascade vers les variantes
        // secondaires, pas un simple champ manquant sur le formulaire lui-même.
        $produit = $this->creerProduitMultiVariantes();
        $ancienTypeId = $produit->produit_type_id;

        try {
            $this->service->mettreAJourSimple($produit, [
                'produit_type_id' => $this->typeId('fabricable'),
                'prix_usine' => 8000,
                'prix_usine_tricycle' => 7500,
                'prix_vente' => 10000,
            ]);
            $this->fail('ValidationException attendue : ni "Noir" ni "Blanc" n\'ont de prix_usine/prix_usine_tricycle.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('produit_type_id', $e->errors());
        }

        $produit->refresh();
        $this->assertSame($ancienTypeId, $produit->produit_type_id, 'Le type ne doit pas avoir changé en base.');
        $this->assertCount(2, $produit->variantes, 'Aucune variante ne doit avoir été créée ni modifiée par un changement refusé.');
        $this->assertTrue($produit->variantes->every(fn ($v) => $v->prix_achat === 8000), 'Aucune écriture partielle ne doit persister.');
    }

    public function test_changement_de_type_est_accepte_si_toutes_les_variantes_restent_coherentes(): void
    {
        $produit = $this->creerProduitMultiVariantes();

        // Les deux variantes ont prix_achat=8000/prix_vente=10000 : compatibles avec FABRICABLE
        // en réinterprétant prix_achat comme prix_usine/prix_usine_tricycle (mêmes valeurs
        // numériques, chacune < 10000).
        foreach ($produit->variantes as $variante) {
            $variante->update([
                'prix_usine' => 8000, 'prix_usine_tricycle' => 7500,
                ...self::PRIX_NATURE_NEUTRES,
            ]);
        }

        $produit = $this->service->mettreAJourSimple($produit->fresh(), [
            'produit_type_id' => $this->typeId('fabricable'),
            'prix_usine' => 8000,
            'prix_usine_tricycle' => 7500,
            'prix_vente' => 10000,
            ...self::PRIX_NATURE_NEUTRES,
        ]);

        $this->assertSame($this->typeId('fabricable'), $produit->produit_type_id);
    }
}
