<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * EnsurePasswordIsNotExpired + ForcePasswordChangeController — un compte avec
 * must_change_password=true (cf. InstallApp) ne doit accéder à rien d'autre tant qu'il n'a pas
 * défini son propre mot de passe, y compris en tapant une URL directement.
 */
class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function makeMustChangeUser(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'must_change_password' => true,
            'password' => Hash::make('Provisoire@2025'),
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_redirects_to_force_change_page_when_accessing_dashboard(): void
    {
        $user = $this->makeMustChangeUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('password.force-change'));
    }

    public function test_redirects_even_for_direct_url_access_to_another_backoffice_page(): void
    {
        $user = $this->makeMustChangeUser();

        $this->actingAs($user)
            ->get('/backoffice/produits')
            ->assertRedirect(route('password.force-change'));
    }

    public function test_show_returns_200_for_user_who_must_change_password(): void
    {
        $user = $this->makeMustChangeUser();

        $this->actingAs($user)
            ->get(route('password.force-change'))
            ->assertStatus(200);
    }

    public function test_show_redirects_away_if_password_already_changed(): void
    {
        $user = $this->makeMustChangeUser();
        $user->update(['must_change_password' => false]);

        $this->actingAs($user)
            ->get(route('password.force-change'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_update_sets_new_password_and_clears_flag(): void
    {
        $user = $this->makeMustChangeUser();

        $this->actingAs($user)
            ->post(route('password.force-change.update'), [
                'password' => 'NouveauMdp$2025',
                'password_confirmation' => 'NouveauMdp$2025',
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NouveauMdp$2025', $user->password));
    }

    public function test_update_fails_without_matching_confirmation(): void
    {
        $user = $this->makeMustChangeUser();

        $this->actingAs($user)
            ->post(route('password.force-change.update'), [
                'password' => 'NouveauMdp$2025',
                'password_confirmation' => 'AutreChose$2025',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_update_returns_403_for_user_without_the_flag(): void
    {
        $user = $this->makeMustChangeUser();
        $user->update(['must_change_password' => false]);

        $this->actingAs($user)
            ->post(route('password.force-change.update'), [
                'password' => 'NouveauMdp$2025',
                'password_confirmation' => 'NouveauMdp$2025',
            ])
            ->assertStatus(403);
    }

    public function test_user_can_access_dashboard_normally_after_changing_password(): void
    {
        $user = $this->makeMustChangeUser();
        $user->sites()->attach(Site::factory()->create(['organization_id' => $user->organization_id])->id, ['is_default' => true]);

        $this->actingAs($user)
            ->post(route('password.force-change.update'), [
                'password' => 'NouveauMdp$2025',
                'password_confirmation' => 'NouveauMdp$2025',
            ]);

        $this->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertStatus(200);
    }
}
