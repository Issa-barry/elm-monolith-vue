<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Produit;
use App\Services\ProduitService;
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
    }

    private function creer(array $overrides): Produit
    {
        return $this->service->creer(array_merge([
            'organization_id' => $this->org->id,
            'nom' => 'Produit test',
            'statut' => 'actif',
        ], $overrides));
    }

    // ── FABRICABLE ────────────────────────────────────────────────────────────

    public function test_fabricable_accepte_prix_vente_superieur_au_prix_usine(): void
    {
        $produit = $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 5100,
            'prix_vente' => 6000,
        ]);

        $this->assertSame(6000, $produit->variantes->first()->prix_vente);
    }

    public function test_fabricable_refuse_prix_vente_egal_au_prix_usine(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 18000,
            'prix_vente' => 18000,
        ]);
    }

    public function test_fabricable_refuse_prix_vente_inferieur_au_prix_usine(): void
    {
        $this->expectException(ValidationException::class);

        $this->creer([
            'type' => 'fabricable',
            'prix_usine' => 18000,
            'prix_vente' => 17999,
        ]);
    }

    public function test_fabricable_refuse_si_prix_usine_absent(): void
    {
        try {
            $this->creer(['type' => 'fabricable', 'prix_vente' => 6000]);
            $this->fail('ValidationException attendue.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }
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
            $this->assertArrayHasKey('type', $e->errors());
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
        $produit = $this->creer(['type' => 'fabricable', 'prix_usine' => 18000, 'prix_vente' => 20000]);

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
        $produit = $this->creer(['type' => 'fabricable', 'prix_usine' => 5100, 'prix_vente' => 6000]);

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
        $produit = $this->creer(['type' => 'fabricable', 'prix_usine' => 5100, 'prix_vente' => 6000]);

        $produit = $this->service->mettreAJourSimple($produit, ['nom' => 'Nouveau nom']);

        $this->assertSame('Nouveau nom', $produit->nom);
        $this->assertSame(6000, $produit->variantes->first()->prix_vente);
    }

    // ── Changement de type : produit à variante unique ───────────────────────

    public function test_changement_de_type_accepte_si_la_variante_unique_reste_coherente(): void
    {
        $produit = $this->creer(['type' => 'achat_vente', 'prix_achat' => 100000, 'prix_vente' => 120000]);

        $produit = $this->service->mettreAJourSimple($produit, [
            'type' => 'fabricable',
            'prix_usine' => 100000,
            'prix_vente' => 120000,
        ]);

        $this->assertSame('fabricable', $produit->type->value);
    }

    // ── Changement de type : produit multi-variantes (scénario critique) ────

    /** Produit à 2 variantes (Couleur: Noir/Blanc) créé achat_vente, prix_achat=8000/prix_vente=10000 sur les deux. */
    private function creerProduitMultiVariantes(): Produit
    {
        return $this->service->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit multi-variantes',
            'type' => 'achat_vente',
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
        // prix_vente=10000/prix_usine=null : valide pour ACHAT_VENTE, mais FABRICABLE exige
        // prix_usine, absent sur les deux. Le changement doit donc être refusé.
        $produit = $this->creerProduitMultiVariantes();

        try {
            $this->service->mettreAJourSimple($produit, [
                'type' => 'fabricable',
                'prix_usine' => 8000,
                'prix_vente' => 10000,
            ]);
            $this->fail('ValidationException attendue : ni "Noir" ni "Blanc" n\'ont de prix_usine.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
        }

        $produit->refresh();
        $this->assertSame('achat_vente', $produit->type->value, 'Le type ne doit pas avoir changé en base.');
        $this->assertCount(2, $produit->variantes, 'Aucune variante ne doit avoir été créée ni modifiée par un changement refusé.');
        $this->assertTrue($produit->variantes->every(fn ($v) => $v->prix_achat === 8000), 'Aucune écriture partielle ne doit persister.');
    }

    public function test_changement_de_type_est_accepte_si_toutes_les_variantes_restent_coherentes(): void
    {
        $produit = $this->creerProduitMultiVariantes();

        // Les deux variantes ont prix_achat=8000/prix_vente=10000 : compatibles avec FABRICABLE
        // en réinterprétant prix_achat comme prix_usine (même valeur numérique 8000 < 10000).
        foreach ($produit->variantes as $variante) {
            $variante->update(['prix_usine' => 8000]);
        }

        $produit = $this->service->mettreAJourSimple($produit->fresh(), [
            'type' => 'fabricable',
            'prix_usine' => 8000,
            'prix_vente' => 10000,
        ]);

        $this->assertSame('fabricable', $produit->type->value);
    }
}
