<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\JournalComptable;
use App\Models\Organization;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Le plan comptable ne doit plus jamais rester à zéro compte/journal/mapping
 * "parce que quelqu'un a oublié de lancer une commande Artisan" — cf. Organization::boot().
 * Avant ce hook, `comptabilite:bootstrap` n'était appelé nulle part hors des
 * tests (cause racine du ticket "compta_* toujours vide").
 */
class OrganizationComptabiliteBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_une_organisation_provisionne_automatiquement_le_plan_comptable(): void
    {
        $org = Organization::factory()->create();

        $this->assertGreaterThan(0, CompteComptable::where('organization_id', $org->id)->count());
        $this->assertGreaterThan(0, JournalComptable::where('organization_id', $org->id)->count());
        $this->assertGreaterThan(0, CompteMapping::where('organization_id', $org->id)->count());

        // Comptes clés des deux périmètres (dépenses/fiches historique + vente/encaissement).
        $this->assertDatabaseHas('compta_comptes', ['organization_id' => $org->id, 'numero' => '622100']);
        $this->assertDatabaseHas('compta_comptes', ['organization_id' => $org->id, 'numero' => '411000']);
        $this->assertDatabaseHas('compta_comptes', ['organization_id' => $org->id, 'numero' => '701000']);
        $this->assertDatabaseHas('compta_journaux', ['organization_id' => $org->id, 'code' => 'VE']);
    }

    public function test_bootstrap_automatique_est_idempotent_avec_un_appel_manuel(): void
    {
        $org = Organization::factory()->create();
        $avant = CompteComptable::where('organization_id', $org->id)->count();

        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        $this->assertSame($avant, CompteComptable::where('organization_id', $org->id)->count());
    }

    public function test_deux_organisations_ont_chacune_leur_propre_plan_comptable(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $comptesA = CompteComptable::where('organization_id', $orgA->id)->pluck('id');
        $comptesB = CompteComptable::where('organization_id', $orgB->id)->pluck('id');

        $this->assertEmpty($comptesA->intersect($comptesB), 'Les comptes de deux organisations ne doivent jamais se chevaucher.');
        $this->assertGreaterThan(0, $comptesA->count());
        $this->assertGreaterThan(0, $comptesB->count());
    }

    /**
     * Mode shadow : un échec du bootstrap comptable ne doit jamais empêcher la
     * création de l'organisation elle-même — même principe que DepenseObserver/
     * FicheComptabilisationService (§26 de la spec, cf. Organization::boot()).
     */
    public function test_echec_du_bootstrap_nempeche_pas_la_creation_de_lorganisation(): void
    {
        $mock = \Mockery::mock(PlanComptableBootstrapService::class);
        $mock->shouldReceive('bootstrap')->once()->andThrow(new \RuntimeException('panne simulée'));
        App::instance(PlanComptableBootstrapService::class, $mock);

        $org = Organization::factory()->create();

        $this->assertNotNull($org->fresh());
        $this->assertDatabaseHas('organizations', ['id' => $org->id]);
    }
}
