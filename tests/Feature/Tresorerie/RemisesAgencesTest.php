<?php

namespace Tests\Feature\Tresorerie;

use App\Features\ModuleFeature;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Vue « Remises des agences » du Trésor principal (ADR 0016) : montants mesurés — reste à recevoir
 * = calcul du moment, en transit, déjà remis sur la période, attendu = leur somme (jamais
 * « attendu − remis »).
 */
class RemisesAgencesTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $central;

    private Site $cba;

    private CompteTresorerie $caisseCentrale;

    private CompteTresorerie $caisseCba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.recevoir']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);

        $this->central = $this->user->sites()->firstOrFail();
        $this->central->update(['is_central_tresorerie' => true]);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cba', 'type' => 'usine', 'localisation' => 'Cba']);

        $this->caisseCentrale = $this->caisse($this->central, 5_000_000);
        $this->caisseCba = $this->caisse($this->cba, 1_000_000);
    }

    private function caisse(Site $site, float $solde): CompteTresorerie
    {
        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => "Caisse {$site->nom}",
        ]);
        $service = app(SoldeOuvertureTresorerieService::class);
        $service->valider($service->enregistrer($this->org->id, $support, [
            'date_situation' => now()->startOfMonth()->toDateString(),
            'montant' => $solde,
        ], $this->user->id), $this->user->id);

        return $support;
    }

    private function envoyer(Site $origine, CompteTresorerie $depuis, Site $destination, CompteTresorerie $vers, float $montant): MouvementFonds
    {
        $service = app(MouvementFondsService::class);
        $mouvement = $service->creerBrouillon($this->org->id, [
            'site_origine_id' => $origine->id,
            'site_destination_id' => $destination->id,
            'compte_tresorerie_origine_id' => $depuis->id,
            'compte_tresorerie_destination_id' => $vers->id,
            'montant' => $montant,
        ], $this->user->id);

        return $service->envoyer($mouvement, $this->user->id);
    }

    private function ligneCba(): array
    {
        $lignes = [];
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.remises.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$lignes) {
                $lignes = $page->toArray()['props']['lignes'];
            });

        return collect($lignes)->firstWhere('site_id', $this->cba->id);
    }

    public function test_une_agence_sans_remise_doit_tout_son_disponible(): void
    {
        $ligne = $this->ligneCba();

        $this->assertEquals(1_000_000.0, $ligne['reste_a_recevoir']);
        $this->assertEquals(0.0, $ligne['en_transit']);
        $this->assertEquals(0.0, $ligne['deja_remis']);
        $this->assertEquals(1_000_000.0, $ligne['attendu']);
        $this->assertSame('a_remettre', $ligne['statut']);
    }

    public function test_la_tresorerie_principale_n_a_pas_de_ligne(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.remises.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('central.id', $this->central->id)
                ->where('lignes', fn ($lignes) => collect($lignes)->pluck('site_id')->all() === [$this->cba->id]));
    }

    /** Kankan de l'ADR : la remise envoyée sort du reste, jamais soustraite une deuxième fois. */
    public function test_une_remise_envoyee_est_en_transit_et_l_attendu_ne_change_pas(): void
    {
        $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 300_000);

        $ligne = $this->ligneCba();

        $this->assertEquals(700_000.0, $ligne['reste_a_recevoir']);
        $this->assertEquals(300_000.0, $ligne['en_transit']);
        $this->assertEquals(1_000_000.0, $ligne['attendu']);
        $this->assertSame('remise_en_cours', $ligne['statut']);
        $this->assertSame(now()->toDateString(), $ligne['derniere_remise']);
    }

    public function test_une_remise_confirmee_est_deja_remise_sur_la_periode(): void
    {
        $remise = $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 300_000);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $remise), ['compte_tresorerie_destination_id' => $this->caisseCentrale->id])
            ->assertSessionHasNoErrors();

        $ligne = $this->ligneCba();
        $this->assertEquals(300_000.0, $ligne['deja_remis']);
        $this->assertEquals(0.0, $ligne['en_transit']);
        $this->assertEquals(700_000.0, $ligne['reste_a_recevoir']);
        $this->assertEquals(1_000_000.0, $ligne['attendu']);
        $this->assertSame('partiellement_remis', $ligne['statut']);

        // Un autre mois : rien de reçu sur cette période-là.
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.remises.index', ['annee' => now()->subMonths(2)->year, 'mois' => now()->subMonths(2)->month]))
            ->assertInertia(fn (Assert $page) => $page->where('lignes.0.deja_remis', 0));
    }

    public function test_tout_remis_donne_le_statut_remis(): void
    {
        $remise = $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 1_000_000);
        app(MouvementFondsService::class)->recevoir($remise, $this->user->id, $this->caisseCentrale->id);

        $ligne = $this->ligneCba();
        $this->assertEquals(0.0, $ligne['reste_a_recevoir']);
        $this->assertEquals(1_000_000.0, $ligne['deja_remis']);
        $this->assertSame('remis', $ligne['statut']);
    }

    /** Un financement va dans l'autre sens : jamais compté comme une remise. */
    public function test_un_financement_du_tresor_vers_l_agence_n_est_pas_une_remise(): void
    {
        $this->envoyer($this->central, $this->caisseCentrale, $this->cba, $this->caisseCba, 200_000);

        $ligne = $this->ligneCba();
        $this->assertEquals(0.0, $ligne['en_transit']);
        $this->assertEquals(0.0, $ligne['deja_remis']);
    }

    public function test_le_detail_liste_les_remises_et_propose_la_reception(): void
    {
        $remise = $this->envoyer($this->cba, $this->caisseCba, $this->central, $this->caisseCentrale, 300_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.remises.show', $this->cba))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Tresorerie/Remises/Show')
                ->where('remises.0.id', $remise->id)
                ->where('remises.0.statut', 'envoye')
                ->where('remises.0.peut_recevoir', true)
                ->where('supports_reception.0.id', $this->caisseCentrale->id)
                ->where('ligne.en_transit', 300_000));
    }

    public function test_le_detail_refuse_la_tresorerie_principale_et_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $etranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'Ailleurs']);

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.remises.show', $this->central))->assertNotFound();
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.remises.show', $etranger))->assertNotFound();
    }

    public function test_sans_permission_la_page_est_refusee(): void
    {
        $sansDroit = $this->creerUtilisateurNonAdmin($this->central, ['factures.encaisser']);

        $this->actingAs($sansDroit)->get(route('comptabilite.tresorerie.remises.index'))->assertForbidden();
    }

    /** Affecté à la trésorerie principale : sa vue de pilotage couvre toutes les agences. */
    public function test_une_personne_de_la_tresorerie_principale_voit_toutes_les_agences(): void
    {
        $tresorier = $this->creerUtilisateurNonAdmin($this->central, ['tresorerie.read']);

        $this->actingAs($tresorier)
            ->get(route('comptabilite.tresorerie.remises.index'))
            ->assertInertia(fn (Assert $page) => $page->where('lignes.0.site_id', $this->cba->id));
    }

    /** Ailleurs, seules ses agences : une personne de Kouria ne voit pas Cba. */
    public function test_une_personne_d_une_autre_agence_ne_voit_que_ses_agences(): void
    {
        $kouria = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'usine', 'localisation' => 'Kouria']);
        $agent = $this->creerUtilisateurNonAdmin($kouria, ['tresorerie.read']);

        $this->actingAs($agent)
            ->get(route('comptabilite.tresorerie.remises.index'))
            ->assertInertia(fn (Assert $page) => $page->where('lignes', fn ($lignes) => collect($lignes)->pluck('site_id')->all() === [$kouria->id]));
        $this->actingAs($agent)->get(route('comptabilite.tresorerie.remises.show', $this->cba))->assertForbidden();
    }
}
