<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Administration de l'organisation courante (settings/organisation).
 * Toujours scopé à l'organisation de l'utilisateur connecté — jamais de
 * sélection d'une autre organisation.
 */
class OrganisationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Organization $org, string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);

        return $user;
    }

    // ── edit ─────────────────────────────────────────────────────────────────

    public function test_edit_accessible_a_admin_entreprise(): void
    {
        $org = Organization::factory()->create(['name' => 'Fello Demo']);
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->get(route('organisation.edit'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Organisation')
                ->where('organisation.name', 'Fello Demo')
                ->where('organisation.slug', $org->slug)
                ->where('organisation.code', $org->code)
                ->where('login_url', route('login.org', $org->code))
            );
    }

    public function test_edit_refuse_non_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'commerciale');

        $this->actingAs($user)
            ->get(route('organisation.edit'))
            ->assertStatus(403);
    }

    public function test_edit_redirects_unauthenticated(): void
    {
        $this->get(route('organisation.edit'))->assertRedirect(route('login'));
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_update_modifie_le_nom_le_code_et_le_siret(): void
    {
        $org = Organization::factory()->create(['name' => 'Ancien nom']);
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), [
                'name' => 'Fello Demo',
                'code' => 'newcode',
                'siret' => '12345678900012',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'name' => 'Fello Demo',
            'code' => 'NEWCODE',
            'siret' => '12345678900012',
        ]);
    }

    public function test_update_refuse_non_admin(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'manager');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => 'Autre nom', 'code' => $org->code])
            ->assertStatus(403);
    }

    public function test_update_exige_un_nom(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => '', 'code' => $org->code])
            ->assertSessionHasErrors('name');
    }

    public function test_update_exige_un_code(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => $org->name, 'code' => ''])
            ->assertSessionHasErrors('code');
    }

    public function test_update_refuse_un_code_deja_utilise(): void
    {
        $autre = Organization::factory()->create(['code' => 'TAKEN']);
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => $org->name, 'code' => 'TAKEN'])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseHas('organizations', ['id' => $autre->id, 'code' => 'TAKEN']);
    }

    public function test_update_refuse_un_code_avec_caracteres_speciaux(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => $org->name, 'code' => 'FDO-1'])
            ->assertSessionHasErrors('code');
    }

    public function test_update_conserve_son_propre_code(): void
    {
        $org = Organization::factory()->create(['code' => 'ELM']);
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => 'Nouveau nom', 'code' => 'ELM'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_update_uploade_un_logo(): void
    {
        Storage::fake('public');
        $org = Organization::factory()->create();
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), [
                'name' => $org->name,
                'code' => $org->code,
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            ])
            ->assertRedirect();

        $org->refresh();
        $this->assertNotNull($org->logo_path);
        Storage::disk('public')->assertExists($org->logo_path);
    }

    public function test_update_supprime_le_logo(): void
    {
        Storage::fake('public');
        $org = Organization::factory()->create(['logo_path' => 'organizations/existing.webp']);
        Storage::disk('public')->put('organizations/existing.webp', 'contenu');
        $user = $this->makeUser($org, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), [
                'name' => $org->name,
                'code' => $org->code,
                'remove_logo' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'logo_path' => null]);
        Storage::disk('public')->assertMissing('organizations/existing.webp');
    }

    public function test_update_ne_modifie_jamais_une_autre_organisation(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Org A']);
        $orgB = Organization::factory()->create(['name' => 'Org B']);
        $user = $this->makeUser($orgA, 'admin_entreprise');

        $this->actingAs($user)
            ->put(route('organisation.update'), ['name' => 'Org A modifiée', 'code' => $orgA->code])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', ['id' => $orgA->id, 'name' => 'Org A modifiée']);
        $this->assertDatabaseHas('organizations', ['id' => $orgB->id, 'name' => 'Org B']);
    }
}
