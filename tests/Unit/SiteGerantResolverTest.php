<?php

namespace Tests\Unit;

use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Rh\EmployeAffectationService;
use App\Services\Rh\SiteGerantResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteGerantResolverTest extends TestCase
{
    use RefreshDatabase;

    private SiteGerantResolver $resolver;

    private EmployeAffectationService $affectations;

    private Organization $org;

    private User $acteur;

    private Site $site;

    private FonctionRh $gerant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SiteGerantResolver;
        $this->affectations = new EmployeAffectationService;
        $this->org = Organization::factory()->create();
        $this->acteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->site = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->gerant = FonctionRh::create([
            'organization_id' => $this->org->id,
            'libelle' => 'Gérant de dépôt',
            'code' => 'GDE',
            'is_active' => true,
        ]);
    }

    public function test_aucun_occupant_si_personne_naffecte(): void
    {
        $occupants = $this->resolver->occupantsActifsA($this->site, $this->gerant->id, Carbon::now());

        $this->assertCount(0, $occupants);
    }

    public function test_un_employe_avec_la_fonction_recherchee_est_trouve(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null, 'statut' => 'actif']);
        $this->affectations->definir($employe, $this->site, $this->gerant, $this->acteur);

        $occupants = $this->resolver->occupantsActifsA($this->site, $this->gerant->id, Carbon::now());

        $this->assertCount(1, $occupants);
        $this->assertSame($employe->id, $occupants->first()->id);
    }

    public function test_un_employe_inactif_nest_jamais_retourne(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null, 'statut' => 'actif']);
        $this->affectations->definir($employe, $this->site, $this->gerant, $this->acteur);
        $employe->update(['statut' => 'suspendu']);

        $occupants = $this->resolver->occupantsActifsA($this->site, $this->gerant->id, Carbon::now());

        $this->assertCount(0, $occupants);
    }

    public function test_plusieurs_gerants_sur_le_meme_site_sont_tous_retournes(): void
    {
        $e1 = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null, 'statut' => 'actif']);
        $e2 = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null, 'statut' => 'actif']);
        $this->affectations->definir($e1, $this->site, $this->gerant, $this->acteur);
        $this->affectations->definir($e2, $this->site, $this->gerant, $this->acteur);

        $occupants = $this->resolver->occupantsActifsA($this->site, $this->gerant->id, Carbon::now());

        $this->assertCount(2, $occupants);
    }

    /**
     * Le cas exact soulevé en revue : un employé était gérant en janvier, redevient comptable
     * en mars — une commission recalculée pour janvier doit toujours le retrouver comme gérant
     * de l'époque, alors que sa fonction ACTUELLE (employes.fonction_rh_id) est "Comptable".
     */
    public function test_retrouve_un_gerant_historique_meme_apres_un_changement_de_fonction_ulterieur(): void
    {
        $comptable = FonctionRh::create([
            'organization_id' => $this->org->id,
            'libelle' => 'Comptable',
            'code' => 'COM',
            'is_active' => true,
        ]);

        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null, 'statut' => 'actif']);

        Carbon::setTestNow('2026-01-10 09:00:00');
        $this->affectations->definir($employe, $this->site, $this->gerant, $this->acteur);

        Carbon::setTestNow('2026-03-05 09:00:00');
        $this->affectations->definir($employe, $this->site, $comptable, $this->acteur);
        Carbon::setTestNow();

        // Aujourd'hui (après le changement) : plus gérant.
        $this->assertCount(0, $this->resolver->occupantsActifsA($this->site, $this->gerant->id, Carbon::now()));

        // Pour une date de janvier (avant le changement) : toujours retrouvé comme gérant.
        $occupantsJanvier = $this->resolver->occupantsActifsA($this->site, $this->gerant->id, Carbon::parse('2026-01-20'));
        $this->assertCount(1, $occupantsJanvier);
        $this->assertSame($employe->id, $occupantsJanvier->first()->id);
    }

    public function test_historique_liste_toutes_les_periodes_du_site_pour_une_fonction(): void
    {
        $employe = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => null, 'statut' => 'actif']);
        $autreSite = Site::factory()->create(['organization_id' => $this->org->id]);

        Carbon::setTestNow('2026-01-10 09:00:00');
        $this->affectations->definir($employe, $this->site, $this->gerant, $this->acteur);
        Carbon::setTestNow('2026-03-05 09:00:00');
        $this->affectations->definir($employe, $autreSite, $this->gerant, $this->acteur);
        Carbon::setTestNow();

        $historique = $this->resolver->historique($this->site, $this->gerant->id);

        $this->assertCount(1, $historique);
        $this->assertNotNull($historique->first()->fin_at, 'la ligne sur ce site est close depuis le transfert vers autreSite');
    }
}
