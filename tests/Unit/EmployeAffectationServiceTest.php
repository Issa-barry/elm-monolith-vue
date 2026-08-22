<?php

namespace Tests\Unit;

use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\Rh\EmployeAffectationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeAffectationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeAffectationService $service;

    private Organization $org;

    private User $acteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmployeAffectationService;
        $this->org = Organization::factory()->create();
        $this->acteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function site(): Site
    {
        return Site::factory()->create(['organization_id' => $this->org->id]);
    }

    private function fonction(string $libelle = 'Gérant de dépôt'): FonctionRh
    {
        return FonctionRh::create([
            'organization_id' => $this->org->id,
            'libelle' => $libelle,
            'code' => strtoupper(substr($libelle, 0, 3)),
            'is_active' => true,
        ]);
    }

    public function test_affectation_initiale_ouvre_une_ligne_active_et_synchronise_le_cache(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null]);
        $site = $this->site();
        $fonction = $this->fonction();

        $this->service->definir($employe, $site, $fonction, $this->acteur);

        $employe->refresh();
        $this->assertSame($site->id, $employe->site_id);
        $this->assertSame($fonction->id, $employe->fonction_rh_id);

        $active = $employe->affectationActive;
        $this->assertNotNull($active);
        $this->assertSame($site->id, $active->site_id);
        $this->assertSame($fonction->id, $active->fonction_rh_id);
        $this->assertNull($active->fin_at);
        $this->assertSame('affectation_initiale', $active->motif);
    }

    public function test_appel_sans_changement_est_un_no_op_idempotent(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null]);
        $site = $this->site();
        $fonction = $this->fonction();

        $this->service->definir($employe, $site, $fonction, $this->acteur);
        $this->assertSame(1, $employe->affectations()->count());

        $this->service->definir($employe, $site, $fonction, $this->acteur);
        $this->assertSame(1, $employe->affectations()->count(), 'aucune nouvelle ligne pour un appel sans changement');
    }

    public function test_transfert_de_site_ferme_lancienne_ligne_au_meme_instant_que_la_nouvelle_ouvre(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null]);
        $siteA = $this->site();
        $siteB = $this->site();
        $fonction = $this->fonction();

        $this->service->definir($employe, $siteA, $fonction, $this->acteur);
        $ancienne = $employe->affectationActive;

        $this->service->definir($employe, $siteB, $fonction, $this->acteur, 'transfert');

        $ancienne->refresh();
        $nouvelle = $employe->fresh()->affectationActive;

        $this->assertNotNull($ancienne->fin_at);
        $this->assertTrue($ancienne->fin_at->equalTo($nouvelle->debut_at), 'début inclus / fin exclue : aucun chevauchement ni trou');
        $this->assertSame($siteB->id, $nouvelle->site_id);
        $this->assertSame('transfert', $nouvelle->motif);
        $this->assertSame(2, $employe->affectations()->count());
    }

    public function test_changement_de_fonction_seul_garde_le_meme_site(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null]);
        $site = $this->site();
        $fonctionA = $this->fonction('Vendeur');
        $fonctionB = $this->fonction('Comptable');

        $this->service->definir($employe, $site, $fonctionA, $this->acteur);
        $this->service->definir($employe, $site, $fonctionB, $this->acteur);

        $employe->refresh();
        $this->assertSame($site->id, $employe->site_id);
        $this->assertSame($fonctionB->id, $employe->fonction_rh_id);

        $active = $employe->affectationActive;
        $this->assertSame('changement_fonction', $active->motif);
    }

    public function test_lhistorique_deja_clos_nest_jamais_modifie_par_une_affectation_ulterieure(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null]);
        $siteA = $this->site();
        $siteB = $this->site();
        $siteC = $this->site();
        $fonction = $this->fonction();

        $this->service->definir($employe, $siteA, $fonction, $this->acteur);
        $premiere = $employe->affectationActive;
        $premiereFinAvant = null;

        $this->service->definir($employe, $siteB, $fonction, $this->acteur);
        $premiere->refresh();
        $premiereFinAvant = $premiere->fin_at;

        $this->service->definir($employe, $siteC, $fonction, $this->acteur);
        $premiere->refresh();

        $this->assertTrue($premiereFinAvant->equalTo($premiere->fin_at), 'une ligne close ne doit plus jamais être réécrite');
        $this->assertSame(3, $employe->affectations()->count());
    }

    public function test_le_transfert_synchronise_lacces_applicatif_via_la_personne_partagee(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        // Employe::create() directement (pas Employe::factory()->create(['personne_id' => ...])) :
        // la factory a un bug de ré-entrance pré-existant, hors périmètre de cette mission, qui
        // écrase silencieusement un personne_id explicite — cf. AccountValidationServiceTest.
        $employe = Employe::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'matricule' => 'EMP-SYNC',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ]);
        $user = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);

        $siteA = $this->site();
        $siteB = $this->site();
        $fonction = $this->fonction();

        $this->service->definir($employe, $siteA, $fonction, $this->acteur);
        $this->assertTrue($user->fresh()->sites()->where('sites.id', $siteA->id)->exists());

        $this->service->definir($employe, $siteB, $fonction, $this->acteur);
        $user->refresh();
        $this->assertFalse($user->sites()->where('sites.id', $siteA->id)->exists(), 'accès à l\'ancien site retiré');
        $this->assertTrue($user->sites()->where('sites.id', $siteB->id)->exists(), 'accès au nouveau site accordé');
    }

    public function test_sans_user_associe_le_transfert_ne_leve_aucune_erreur(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null]);
        $site = $this->site();
        $fonction = $this->fonction();

        $this->service->definir($employe, $site, $fonction, $this->acteur);

        $this->assertSame($site->id, $employe->fresh()->site_id);
    }
}
