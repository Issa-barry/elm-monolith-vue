<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\Fournisseur;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class FournisseurTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['fournisseurs.read', 'fournisseurs.create', 'fournisseurs.update', 'fournisseurs.delete']);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
    }

    private function makeFournisseur(Organization $org, array $overrides = []): Fournisseur
    {
        return Fournisseur::create(array_merge([
            'organization_id' => $org->id,
            'nom' => 'FOURNISSEUR TEST',
            'is_active' => true,
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'phone' => '622000001',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'is_active' => true,
        ], $overrides);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('fournisseurs.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('fournisseurs.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('fournisseurs.index'))
            ->assertStatus(403);
    }

    public function test_index_isole_par_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->makeFournisseur($autreOrg, ['nom' => 'AILLEURS']);
        $this->makeFournisseur($this->org, ['nom' => 'ICI']);

        $response = $this->actingAs($this->user)->get(route('fournisseurs.index'));

        $noms = collect($response->original->getData()['page']['props']['fournisseurs'])->pluck('nom')->all();
        $this->assertContains('ICI', $noms);
        $this->assertNotContains('AILLEURS', $noms);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('fournisseurs.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('fournisseurs.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_fournisseur_with_nom_prenom_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('fournisseurs.store'), $this->validPayload())
            ->assertRedirect(route('fournisseurs.index'));

        $this->assertDatabaseHas('fournisseurs', [
            'organization_id' => $this->org->id,
            'nom' => 'DIALLO',
        ]);
    }

    public function test_store_creates_fournisseur_with_raison_sociale_only(): void
    {
        $this->actingAs($this->user)
            ->post(route('fournisseurs.store'), $this->validPayload([
                'nom' => null,
                'prenom' => null,
                'raison_sociale' => 'Entreprise ABC',
            ]))
            ->assertRedirect(route('fournisseurs.index'));

        $this->assertDatabaseHas('fournisseurs', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_without_nom_and_raison_sociale(): void
    {
        $this->actingAs($this->user)
            ->post(route('fournisseurs.store'), $this->validPayload([
                'nom' => null,
                'prenom' => null,
                'raison_sociale' => null,
            ]))
            ->assertSessionHasErrors(['nom', 'prenom', 'raison_sociale']);
    }

    public function test_store_fails_with_missing_required_location_and_phone(): void
    {
        $this->actingAs($this->user)
            ->post(route('fournisseurs.store'), $this->validPayload([
                'phone' => null,
                'code_pays' => null,
                'ville' => null,
            ]))
            ->assertSessionHasErrors(['phone', 'code_pays', 'ville']);
    }

    public function test_store_fails_with_invalid_code_pays(): void
    {
        $this->actingAs($this->user)
            ->post(route('fournisseurs.store'), $this->validPayload(['code_pays' => 'XX']))
            ->assertSessionHasErrors('code_pays');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $fournisseur = $this->makeFournisseur($this->org);

        $this->actingAs($this->user)
            ->get(route('fournisseurs.edit', $fournisseur))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $fournisseur = $this->makeFournisseur($otherOrg);

        $this->actingAs($this->user)
            ->get(route('fournisseurs.edit', $fournisseur))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_fournisseur_and_redirects(): void
    {
        $fournisseur = $this->makeFournisseur($this->org);

        $this->actingAs($this->user)
            ->put(route('fournisseurs.update', $fournisseur), $this->validPayload([
                'nom' => 'Barry',
                'prenom' => 'Fatoumata',
            ]))
            ->assertRedirect(route('fournisseurs.edit', $fournisseur));

        $this->assertDatabaseHas('fournisseurs', [
            'id' => $fournisseur->id,
            'nom' => 'BARRY',
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $fournisseur = $this->makeFournisseur($this->org);

        $this->actingAs($this->user)
            ->put(route('fournisseurs.update', $fournisseur), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'raison_sociale', 'phone', 'code_pays', 'ville']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_fournisseur_and_redirects(): void
    {
        $fournisseur = $this->makeFournisseur($this->org);

        $this->actingAs($this->user)
            ->delete(route('fournisseurs.destroy', $fournisseur))
            ->assertRedirect(route('fournisseurs.index'));

        $this->assertSoftDeleted('fournisseurs', ['id' => $fournisseur->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $fournisseur = $this->makeFournisseur($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('fournisseurs.destroy', $fournisseur))
            ->assertStatus(403);
    }

    // ── storeRapide (création rapide depuis le formulaire Produit) ──────────────

    private function rapidePayload(array $overrides = []): array
    {
        return array_merge([
            'raison_sociale' => 'SOGUIDEP',
            'phone' => '622000099',
            'code_pays' => 'GN',
        ], $overrides);
    }

    public function test_store_rapide_cree_un_fournisseur_et_flashe_son_id(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload());

        $response->assertSessionHas('created_fournisseur_id');

        $fournisseur = Fournisseur::where('raison_sociale', 'Soguidep')->firstOrFail();
        $this->assertSame($response->getSession()->get('created_fournisseur_id'), $fournisseur->id);
    }

    public function test_store_rapide_normalise_le_telephone(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload(['phone' => '0622000099']));

        $this->assertDatabaseHas('fournisseurs', [
            'raison_sociale' => 'Soguidep',
            'phone' => '+224622000099',
        ]);
    }

    public function test_store_rapide_ville_et_adresse_sont_facultatives(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload())
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('fournisseurs', [
            'raison_sociale' => 'Soguidep',
            'ville' => null,
            'adresse' => null,
        ]);
    }

    public function test_store_rapide_fails_sans_nom(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload(['raison_sociale' => '']))
            ->assertSessionHasErrors('raison_sociale');
    }

    public function test_store_rapide_fails_sans_telephone(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload(['phone' => '']))
            ->assertSessionHasErrors('phone');
    }

    public function test_store_rapide_refuse_un_telephone_deja_utilise(): void
    {
        $this->makeFournisseur($this->org, [
            'raison_sociale' => 'Existant',
            'phone' => '+224622000099',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload())
            ->assertSessionHasErrors('phone');
    }

    public function test_store_rapide_isole_les_doublons_par_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->makeFournisseur($autreOrg, [
            'raison_sociale' => 'Existant ailleurs',
            'phone' => '+224622000099',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
        ]);

        // Même téléphone, mais autre organisation : pas de conflit.
        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload())
            ->assertSessionDoesntHaveErrors();
    }

    public function test_store_rapide_redirects_unauthenticated(): void
    {
        $this->post(route('produits.fournisseurs.store'), $this->rapidePayload())
            ->assertRedirect(route('login'));
    }

    public function test_store_rapide_returns_403_without_permission(): void
    {
        $user = $this->makeUserWithPermissions($this->org, ['produits.create']);

        $this->actingAs($user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload())
            ->assertStatus(403);
    }

    public function test_store_rapide_fonctionne_meme_module_achats_desactive(): void
    {
        // La création rapide de fournisseur depuis le formulaire Produit est rattachée au
        // module Produits, pas au module Achats — elle doit fonctionner indépendamment.
        Feature::for($this->org)->deactivate(ModuleFeature::ACHATS);

        $this->actingAs($this->user)
            ->post(route('produits.fournisseurs.store'), $this->rapidePayload())
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('created_fournisseur_id');
    }
}
