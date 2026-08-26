<?php

namespace Tests\Unit;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Organization;
use App\Models\TypeVehicule;
use App\Services\Commission\CommissionRegleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Résolution stricte, sans héritage entre catégories (décision AMOA #3) :
 * Variante > Produit > Catégorie exacte > Globale > Aucune règle.
 */
class CommissionRegleResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function creerRegle(
        CommissionScopeType $scope,
        ?string $scopeId,
        float $montant = 100,
        ?string $typeVehiculeId = null,
    ): CommissionRegle {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Règle test',
            'scope_type' => $scope->value,
            'scope_id' => $scopeId,
            'cible_type' => 'equipe_livraison',
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'type_vehicule_id' => $typeVehiculeId,
            'effective_from' => Carbon::today()->subDay(),
            'statut' => 'active',
        ]);
    }

    /** @test */
    public function resout_la_regle_globale_quand_aucune_regle_plus_specifique_nexiste(): void
    {
        $globale = $this->creerRegle(CommissionScopeType::GLOBAL, null, 300);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: 'var-1', produitId: 'prod-1', categorieId: 'cat-1',
            date: Carbon::today(),
        );

        $this->assertSame($globale->id, $resolue?->id);
    }

    /** @test */
    public function la_regle_categorie_prevaut_sur_la_regle_globale(): void
    {
        $this->creerRegle(CommissionScopeType::GLOBAL, null, 300);
        $categorie = $this->creerRegle(CommissionScopeType::CATEGORIE, 'cat-1', 400);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: 'var-1', produitId: 'prod-1', categorieId: 'cat-1',
            date: Carbon::today(),
        );

        $this->assertSame($categorie->id, $resolue?->id);
    }

    /** @test */
    public function une_exception_vehicule_remplace_le_bareme_general_uniquement_pour_ce_type(): void
    {
        $tricycle = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $camion = TypeVehicule::factory()->create(['organization_id' => $this->org->id]);
        $generale = $this->creerRegle(CommissionScopeType::CATEGORIE, 'cat-1', 400);
        $exception = $this->creerRegle(
            CommissionScopeType::CATEGORIE,
            'cat-1',
            650,
            $tricycle->id,
        );

        $pourTricycle = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: null, produitId: null, categorieId: 'cat-1',
            date: Carbon::today(), typeVehiculeId: $tricycle->id,
        );
        $pourCamion = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: null, produitId: null, categorieId: 'cat-1',
            date: Carbon::today(), typeVehiculeId: $camion->id,
        );

        $this->assertSame($exception->id, $pourTricycle?->id);
        $this->assertSame($generale->id, $pourCamion?->id);
    }

    /** @test */
    public function la_regle_produit_prevaut_sur_la_regle_categorie(): void
    {
        $this->creerRegle(CommissionScopeType::CATEGORIE, 'cat-1', 400);
        $produit = $this->creerRegle(CommissionScopeType::PRODUIT, 'prod-1', 500);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: 'var-1', produitId: 'prod-1', categorieId: 'cat-1',
            date: Carbon::today(),
        );

        $this->assertSame($produit->id, $resolue?->id);
    }

    /** @test */
    public function la_regle_variante_prevaut_sur_tout_le_reste(): void
    {
        $this->creerRegle(CommissionScopeType::PRODUIT, 'prod-1', 500);
        $variante = $this->creerRegle(CommissionScopeType::VARIANTE, 'var-1', 600);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: 'var-1', produitId: 'prod-1', categorieId: 'cat-1',
            date: Carbon::today(),
        );

        $this->assertSame($variante->id, $resolue?->id);
    }

    /** @test */
    public function aucune_regle_definie_sur_une_categorie_parente_ne_sapplique_a_lenfant(): void
    {
        // Décision AMOA #3 : pas d'héritage. Une règle sur "cat-parent" ne doit
        // jamais être retrouvée pour une ligne dont la catégorie exacte est "cat-enfant".
        $this->creerRegle(CommissionScopeType::CATEGORIE, 'cat-parent', 400);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: null, produitId: null, categorieId: 'cat-enfant',
            date: Carbon::today(),
        );

        $this->assertNull($resolue);
    }

    /** @test */
    public function retourne_null_si_aucune_regle_ne_correspond(): void
    {
        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: 'var-1', produitId: 'prod-1', categorieId: 'cat-1',
            date: Carbon::today(),
        );

        $this->assertNull($resolue);
    }

    /** @test */
    public function ignore_une_regle_dont_la_date_deffet_nest_pas_encore_atteinte(): void
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Future',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => 'equipe_livraison',
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 999,
            'effective_from' => Carbon::today()->addMonth(),
            'statut' => 'active',
        ]);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: null, produitId: null, categorieId: null,
            date: Carbon::today(),
        );

        $this->assertNull($resolue);
    }

    /** @test */
    public function ignore_une_regle_expiree(): void
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Ancienne',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => 'equipe_livraison',
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 300,
            'effective_from' => Carbon::today()->subYear(),
            'effective_to' => Carbon::today()->subDay(),
            'statut' => 'active',
        ]);

        $resolue = CommissionRegleResolver::resolve(
            $this->org->id, $this->processus->id, 'equipe_livraison',
            varianteId: null, produitId: null, categorieId: null,
            date: Carbon::today(),
        );

        $this->assertNull($resolue);
    }
}
