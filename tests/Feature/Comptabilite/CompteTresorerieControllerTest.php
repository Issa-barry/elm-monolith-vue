<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Verrouille le formulaire de configuration des supports de trésorerie —
 * absence totale de test avant le 2026-08-22, ce qui a laissé passer un bug
 * signalé par l'utilisateur : le formulaire "refuse de créer" sans jamais
 * afficher pourquoi. Root cause (corrigée côté Vue, pas testable ici) :
 * `form.post()` n'avait pas `preserveState: true`, et la page ne rendait
 * aucune erreur de validation — un libellé oublié réinitialisait
 * silencieusement tout le formulaire au lieu d'afficher l'erreur. Ces tests
 * verrouillent au moins le comportement serveur (validation + persistance).
 */
class CompteTresorerieControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture']);
    }

    public function test_index_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertStatus(200);
    }

    public function test_store_cree_un_support_avec_donnees_valides(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => 'Caisse Matoto',
            ])
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'));

        $this->assertDatabaseHas('compta_supports_tresorerie', [
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'libelle' => 'Caisse Matoto',
        ]);
    }

    /**
     * Reproduit exactement le cas signalé : libellé oublié. Doit échouer
     * proprement (422/redirect avec erreur), jamais silencieusement.
     */
    public function test_store_refuse_sans_libelle_et_ne_persiste_rien(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => '',
            ])
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'))
            ->assertSessionHasErrors('libelle');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_store_refuse_un_site_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'X', 'type' => 'depot', 'localisation' => 'Y']);
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $autreSite->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => 'Intrusion',
            ])
            ->assertSessionHasErrors('site_id');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_store_refuse_sans_permission(): void
    {
        $this->user->syncPermissions([]);
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => 'Test',
            ])
            ->assertStatus(403);
    }

    public function test_update_modifie_le_libelle(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Ancien nom',
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Nouveau nom',
                'actif' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Nouveau nom', $support->fresh()->libelle);
    }
}
