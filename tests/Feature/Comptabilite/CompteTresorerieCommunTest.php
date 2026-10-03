<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Compte commun (ADR 0016, lot 0) : un support utilisé par plusieurs agences mais détenu par une
 * seule — `site_id` = agence détentrice, pivot = agences utilisatrices (détentrice comprise).
 */
class CompteTresorerieCommunTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $detentrice;

    private Site $cba;

    private Site $kouria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture']);
        $this->detentrice = $this->user->sites()->first();
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cba', 'type' => 'usine', 'localisation' => 'Cba']);
        $this->kouria = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'usine', 'localisation' => 'Kouria']);
    }

    private function compte(string $numero): CompteComptable
    {
        return CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
    }

    /** @param  array<string, mixed>  $donnees */
    private function creerCommun(array $donnees = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), array_merge([
                'nature' => 'commun',
                'site_id' => $this->detentrice->id,
                'type' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
                'compte_comptable_id' => $this->compte('561100')->id,
                'libelle' => 'Orange Money',
                'agences_utilisatrices' => [$this->cba->id, $this->kouria->id],
            ], $donnees));
    }

    public function test_cree_un_compte_commun_detenu_par_une_agence_et_utilise_par_d_autres(): void
    {
        $this->creerCommun()->assertSessionHasNoErrors();

        $support = CompteTresorerie::where('libelle', 'Orange Money')->firstOrFail();
        $this->assertTrue($support->commun);
        $this->assertSame($this->detentrice->id, $support->site_id);
        $this->assertFalse($support->actif);
        // La détentrice est toujours utilisatrice, même si le formulaire ne l'envoie pas.
        $this->assertEqualsCanonicalizing(
            [$this->detentrice->id, $this->cba->id, $this->kouria->id],
            $support->agencesUtilisatrices()->pluck('sites.id')->all(),
        );
    }

    public function test_un_support_propre_n_a_aucune_agence_utilisatrice(): void
    {
        $this->creerCommun(['nature' => 'agence'])->assertSessionHasNoErrors();

        $support = CompteTresorerie::where('libelle', 'Orange Money')->firstOrFail();
        $this->assertFalse($support->commun);
        $this->assertSame(0, $support->agencesUtilisatrices()->count());
    }

    public function test_un_compte_commun_exige_une_autre_agence_que_la_detentrice(): void
    {
        $this->creerCommun(['agences_utilisatrices' => []])->assertSessionHasErrors('agences_utilisatrices');
        $this->creerCommun(['agences_utilisatrices' => [$this->detentrice->id]])->assertSessionHasErrors('agences_utilisatrices');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_une_caisse_ne_peut_pas_etre_un_compte_commun(): void
    {
        $this->creerCommun([
            'type' => 'caisse',
            'operateur_mobile_money' => null,
            'compte_comptable_id' => $this->compte('571000')->id,
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_une_banque_peut_etre_un_compte_commun(): void
    {
        $this->creerCommun([
            'type' => 'banque',
            'operateur_mobile_money' => null,
            'compte_comptable_id' => $this->compte('521000')->id,
            'libelle' => 'UBA',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(CompteTresorerie::where('libelle', 'UBA')->firstOrFail()->commun);
    }

    public function test_refuse_une_agence_utilisatrice_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::create(['name' => 'Autre org', 'slug' => 'autre-org-commun']);
        $etranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'Ailleurs']);

        $this->creerCommun(['agences_utilisatrices' => [$this->cba->id, $etranger->id]])
            ->assertSessionHasErrors('agences_utilisatrices.1');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_une_agence_n_utilise_qu_un_compte_commun_par_compte_mobile_money(): void
    {
        $this->creerCommun()->assertSessionHasNoErrors();

        // Autre détentrice, même compte Orange Money, Cba déjà utilisatrice du premier.
        $this->creerCommun([
            'site_id' => $this->kouria->id,
            'libelle' => 'Orange Money bis',
            'agences_utilisatrices' => [$this->cba->id],
        ])->assertSessionHasErrors('agences_utilisatrices');

        $this->assertSame(1, CompteTresorerie::where('commun', true)->count());
    }

    public function test_update_rend_commun_un_support_existant_sans_changer_sa_detentrice(): void
    {
        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->detentrice->id,
            'compte_comptable_id' => $this->compte('561100')->id,
            'type' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'libelle' => 'Mobile Money de Matoto',
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Orange Money',
                'type' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
                'compte_comptable_id' => $this->compte('561100')->id,
                'actif' => true,
                'commun' => true,
                'agences_utilisatrices' => [$this->cba->id],
                'site_id' => $this->cba->id,
            ])
            ->assertSessionHasNoErrors();

        $support->refresh();
        $this->assertTrue($support->commun);
        $this->assertSame($this->detentrice->id, $support->site_id);
        $this->assertEqualsCanonicalizing([$this->detentrice->id, $this->cba->id], $support->agencesUtilisatrices()->pluck('sites.id')->all());
    }

    public function test_update_sans_le_champ_commun_conserve_les_agences(): void
    {
        $this->creerCommun()->assertSessionHasNoErrors();
        $support = CompteTresorerie::where('libelle', 'Orange Money')->firstOrFail();

        // Même requête que « Désactiver / Réactiver » du menu ⋮ : aucun champ commun envoyé.
        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Orange Money',
                'type' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
                'compte_comptable_id' => $this->compte('561100')->id,
                'actif' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($support->fresh()->commun);
        $this->assertSame(3, $support->agencesUtilisatrices()->count());
    }

    public function test_update_rend_propre_un_compte_commun(): void
    {
        $this->creerCommun()->assertSessionHasNoErrors();
        $support = CompteTresorerie::where('libelle', 'Orange Money')->firstOrFail();

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Orange Money',
                'type' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
                'compte_comptable_id' => $this->compte('561100')->id,
                'actif' => false,
                'commun' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($support->fresh()->commun);
        $this->assertSame(0, $support->agencesUtilisatrices()->count());
    }

    public function test_le_numero_du_compte_est_enregistre_et_conserve_sans_etre_renvoye(): void
    {
        $this->creerCommun(['numero' => '+224 620 00 00 00'])->assertSessionHasNoErrors();
        $support = CompteTresorerie::where('libelle', 'Orange Money')->firstOrFail();
        $this->assertSame('+224 620 00 00 00', $support->numero);

        // Activation depuis le menu ⋮ : le numéro n'est pas envoyé, il reste inchangé.
        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Orange Money',
                'type' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
                'compte_comptable_id' => $this->compte('561100')->id,
                'actif' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('+224 620 00 00 00', $support->fresh()->numero);
    }

    public function test_index_propose_le_site_central_de_tresorerie_comme_detentrice(): void
    {
        Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'agence', 'is_central_tresorerie' => true, 'localisation' => 'Siège']);
        $principal = Site::where('organization_id', $this->org->id)->where('is_central_tresorerie', true)->value('id');
        $this->assertNotNull($principal);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertInertia(fn (Assert $page) => $page->where('site_central_tresorerie_id', $principal));
    }

    public function test_index_expose_le_compte_commun_et_ses_agences(): void
    {
        $this->creerCommun()->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index', ['nature' => 'commun']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('comptes', 1)
                ->where('comptes.0.commun', true)
                ->where('comptes.0.site_id', $this->detentrice->id)
                ->where('comptes.0.agences_utilisatrices', fn ($agences) => collect($agences)->pluck('nom')->sort()->values()->all()
                    === collect([$this->detentrice->nom, 'Cba', 'Kouria'])->sort()->values()->all()));

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index', ['nature' => 'agence']))
            ->assertInertia(fn (Assert $page) => $page->has('comptes', 0));
    }
}
