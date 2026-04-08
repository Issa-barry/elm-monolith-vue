<?php

namespace Tests\Feature;

use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class EquipeLivraisonTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'equipes-livraison.read',
            'equipes-livraison.create',
            'equipes-livraison.update',
            'equipes-livraison.delete',
        ]);
    }

    private function validPayload(int $proprietaireId, array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Équipe Test',
            'is_active' => true,
            'proprietaire_id' => $proprietaireId,
            'taux_commission_proprietaire' => 60,
            'membres' => [
                [
                    'livreur_id' => null,
                    'nom' => 'Diallo',
                    'prenom' => 'Mamadou',
                    'telephone' => '620000001',
                    'role' => 'principal',
                    'taux_commission' => 30,
                    'ordre' => 0,
                ],
            ],
        ], $overrides);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('equipes-livraison.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('equipes-livraison.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('equipes-livraison.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('equipes-livraison.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_equipe_avec_proprietaire_meme_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertDatabaseHas('equipes_livraison', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'nom' => 'Équipe Test',
            'taux_commission_proprietaire' => 60,
        ]);
    }

    public function test_store_echoue_si_proprietaire_id_absent(): void
    {
        $payload = $this->validPayload(0);
        unset($payload['proprietaire_id']);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $payload)
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_echoue_si_proprietaire_autre_org(): void
    {
        $autreOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id))
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_persiste_taux_commission_proprietaire(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'taux_commission_proprietaire' => 75.50,
            ]))
            ->assertRedirect(route('equipes-livraison.index'));

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)
            ->where('proprietaire_id', $proprietaire->id)
            ->first();

        $this->assertNotNull($equipe);
        $this->assertEquals(75.50, (float) $equipe->taux_commission_proprietaire);
    }

    public function test_store_echoue_si_taux_superieur_100(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'taux_commission_proprietaire' => 101,
            ]))
            ->assertSessionHasErrors('taux_commission_proprietaire');
    }

    public function test_store_echoue_si_taux_negatif(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'taux_commission_proprietaire' => -1,
            ]))
            ->assertSessionHasErrors('taux_commission_proprietaire');
    }

    public function test_store_echoue_sans_membre(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [],
            ]))
            ->assertSessionHasErrors('membres');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $this->actingAs($this->user)
            ->get(route('equipes-livraison.edit', $equipe))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $autreOrg->id,
            'proprietaire_id' => $proprietaire->id,
            'nom' => 'Équipe Autre Org',
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);

        $this->actingAs($this->user)
            ->get(route('equipes-livraison.edit', $equipe))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifie_equipe_et_redirige(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $nouveauProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($nouveauProprietaire->id, [
                'nom' => 'Équipe Modifiée',
                'taux_commission_proprietaire' => 55,
            ]))
            ->assertRedirect(route('equipes-livraison.edit', $equipe));

        $this->assertDatabaseHas('equipes_livraison', [
            'id' => $equipe->id,
            'proprietaire_id' => $nouveauProprietaire->id,
            'nom' => 'Équipe Modifiée',
            'taux_commission_proprietaire' => 55,
        ]);
    }

    public function test_update_echoue_si_proprietaire_autre_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $autreOrg = Organization::factory()->create();
        $autreProprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($autreProprietaire->id))
            ->assertSessionHasErrors('proprietaire_id');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_supprime_equipe_et_redirige(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $this->actingAs($this->user)
            ->delete(route('equipes-livraison.destroy', $equipe))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertSoftDeleted('equipes_livraison', ['id' => $equipe->id]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeEquipe(int $proprietaireId): EquipeLivraison
    {
        return EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireId,
            'nom' => 'Équipe Fixture',
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);
    }
}
