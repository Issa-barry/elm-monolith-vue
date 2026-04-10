<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProprietaireTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['proprietaires.read', 'proprietaires.create', 'proprietaires.update', 'proprietaires.delete']);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('proprietaires.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('proprietaires.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('proprietaires.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('proprietaires.create'))
            ->assertStatus(200);
    }

    public function test_create_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('proprietaires.create'))
            ->assertStatus(403);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_proprietaire_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('proprietaires.store'), [
                'nom' => 'Camara',
                'prenom' => 'Ibrahima',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertRedirect(route('proprietaires.index'));

        $this->assertDatabaseHas('proprietaires', [
            'nom' => 'CAMARA',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('proprietaires.store'), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'telephone', 'code_pays', 'ville']);
    }

    public function test_store_fails_with_invalid_code_pays(): void
    {
        $this->actingAs($this->user)
            ->post(route('proprietaires.store'), [
                'nom' => 'Camara',
                'prenom' => 'Ibrahima',
                'telephone' => '622000001',
                'code_pays' => 'XX',
                'ville' => 'Conakry',
            ])
            ->assertSessionHasErrors('code_pays');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->get(route('proprietaires.edit', $proprietaire))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->get(route('proprietaires.edit', $proprietaire))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_proprietaire_and_redirects(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('proprietaires.update', $proprietaire), [
                'nom' => 'Balde',
                'prenom' => 'Thierno',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'is_active' => true,
            ])
            ->assertRedirect(route('proprietaires.edit', $proprietaire));

        $this->assertDatabaseHas('proprietaires', [
            'id' => $proprietaire->id,
            'nom' => 'BALDE',
        ]);
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->put(route('proprietaires.update', $proprietaire), [])
            ->assertSessionHasErrors(['nom', 'prenom', 'telephone', 'code_pays', 'ville']);
    }

    // ── unicité par organisation ──────────────────────────────────────────────

    public function test_store_refuses_duplicate_telephone_in_same_org(): void
    {
        Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->post(route('proprietaires.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001', // même numéro, format local → canonique +224622000001
                'code_pays' => 'GN',
                'ville' => 'Conakry',
            ])
            ->assertSessionHasErrors('telephone');
    }

    public function test_store_refuses_duplicate_email_in_same_org(): void
    {
        Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'test@example.com',
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->post(route('proprietaires.store'), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'email' => 'test@example.com', // même email
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_update_allows_same_proprietaire_to_keep_telephone(): void
    {
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
        ]);

        $this->actingAs($this->user)
            ->put(route('proprietaires.update', $proprietaire), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000001', // son propre numéro → doit passer
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertRedirect(route('proprietaires.edit', $proprietaire));
    }

    public function test_update_refuses_telephone_conflict_with_other_proprietaire(): void
    {
        Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000002',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
        ]);

        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
        ]);

        $this->actingAs($this->user)
            ->put(route('proprietaires.update', $proprietaire), [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'telephone' => '622000002', // numéro déjà pris par l'autre propriétaire
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('telephone');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_proprietaire_and_redirects(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->delete(route('proprietaires.destroy', $proprietaire))
            ->assertRedirect(route('proprietaires.index'));

        $this->assertSoftDeleted('proprietaires', ['id' => $proprietaire->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->user)
            ->delete(route('proprietaires.destroy', $proprietaire))
            ->assertStatus(403);
    }
}
