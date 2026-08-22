<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutSoldeOuverture;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\SoldeOuvertureTresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Aucun test HTTP n'existait pour ce contrôleur avant le 2026-08-22 — c'est
 * exactement ce qui a laissé passer le bug signalé : le bouton "Valider"
 * appelait la route avec l'ID du CompteTresorerie au lieu de l'ID du
 * SoldeOuvertureTresorerie (route model binding sur le mauvais modèle),
 * provoquant une 404 systématique. Le fix est côté Vue (Supports/Index.vue
 * utilise désormais compte.solde_ouverture.id) ; ces tests verrouillent le
 * comportement serveur pour qu'une régression future soit détectée ici.
 */
class SoldeOuvertureTresorerieControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private CompteTresorerie $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture']);

        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Caisse Test',
        ]);
    }

    public function test_store_cree_un_solde_en_brouillon(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.soldes-ouverture.store'), [
                'compte_tresorerie_id' => $this->support->id,
                'date_situation' => '2026-08-01',
                'montant' => 20_000_000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('compta_soldes_ouverture', [
            'compte_tresorerie_id' => $this->support->id,
            'montant' => 20_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);
    }

    /**
     * Défense en profondeur : même si le frontend envoie déjà la valeur brute,
     * le serveur tolère un montant encore formaté ("20 000 000").
     */
    public function test_store_normalise_un_montant_forme(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.soldes-ouverture.store'), [
                'compte_tresorerie_id' => $this->support->id,
                'date_situation' => '2026-08-01',
                'montant' => '20 000 000',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('compta_soldes_ouverture', [
            'compte_tresorerie_id' => $this->support->id,
            'montant' => 20_000_000,
        ]);
    }

    public function test_store_refuse_sans_permission(): void
    {
        $this->user->syncPermissions([]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.soldes-ouverture.store'), [
                'compte_tresorerie_id' => $this->support->id,
                'date_situation' => '2026-08-01',
                'montant' => 1_000_000,
            ])
            ->assertStatus(403);
    }

    public function test_valider_avec_lidentifiant_du_solde_reussit(): void
    {
        $solde = SoldeOuvertureTresorerie::create([
            'organization_id' => $this->org->id,
            'compte_tresorerie_id' => $this->support->id,
            'date_situation' => '2026-08-01',
            'montant' => 20_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.soldes-ouverture.valider', $solde))
            ->assertRedirect();

        $this->assertSame(StatutSoldeOuverture::VALIDE, $solde->fresh()->statut);
    }

    /**
     * Reproduit exactement le bug signalé : appeler la route de validation
     * avec l'ID du SUPPORT de trésorerie (compte.id) au lieu de l'ID du solde
     * d'ouverture doit échouer proprement (404), jamais valider le mauvais
     * enregistrement ni planter silencieusement.
     */
    public function test_valider_avec_lidentifiant_du_support_echoue_en_404(): void
    {
        SoldeOuvertureTresorerie::create([
            'organization_id' => $this->org->id,
            'compte_tresorerie_id' => $this->support->id,
            'date_situation' => '2026-08-01',
            'montant' => 20_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        $this->actingAs($this->user)
            ->post("/backoffice/comptabilite/tresorerie/soldes-ouverture/{$this->support->id}/valider")
            ->assertStatus(404);
    }

    public function test_double_validation_est_protegee(): void
    {
        $solde = SoldeOuvertureTresorerie::create([
            'organization_id' => $this->org->id,
            'compte_tresorerie_id' => $this->support->id,
            'date_situation' => '2026-08-01',
            'montant' => 20_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.soldes-ouverture.valider', $solde));
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.soldes-ouverture.valider', $solde))->assertRedirect();

        $solde->refresh();
        $this->assertSame(StatutSoldeOuverture::VALIDE, $solde->statut);
        $this->assertSame(1, PieceComptable::where('source_id', $solde->id)->count());
    }

    public function test_valider_refuse_un_solde_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'X', 'type' => 'depot', 'localisation' => 'Y']);
        $autreCompte = CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail();
        $autreSupport = CompteTresorerie::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'compte_comptable_id' => $autreCompte->id,
            'type' => 'caisse',
            'libelle' => 'Autre Caisse',
        ]);
        $autreSolde = SoldeOuvertureTresorerie::create([
            'organization_id' => $autreOrg->id,
            'compte_tresorerie_id' => $autreSupport->id,
            'date_situation' => '2026-08-01',
            'montant' => 5_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.soldes-ouverture.valider', $autreSolde))
            ->assertStatus(403);
    }
}
