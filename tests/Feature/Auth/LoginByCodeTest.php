<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Route /login/{code} : affiche la page de connexion avec le logo/nom de
 * l'organisation identifiée par son "trinôme", avant toute authentification.
 * L'authentification elle-même reste sur POST /login (Fortify), inchangée.
 */
class LoginByCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiche_le_branding_de_lorganisation_identifiee_par_son_code(): void
    {
        $org = Organization::factory()->create(['name' => 'Fello Demo', 'code' => 'FDO']);

        $this->get(route('login.org', 'FDO'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('orgBranding.name', 'Fello Demo')
            );

        // Le code réel appartient bien à l'organisation créée.
        $this->assertSame($org->code, 'FDO');
    }

    public function test_code_inconnu_retourne_404(): void
    {
        $this->get(route('login.org', 'INCONNU'))->assertStatus(404);
    }

    public function test_login_par_defaut_nexpose_aucun_branding(): void
    {
        $this->get(route('login'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('orgBranding', null)
            );
    }

    public function test_utilisateur_deja_authentifie_est_redirige(): void
    {
        Organization::factory()->create(['code' => 'FDO']);
        $user = User::factory()->withoutTwoFactor()->create();

        $this->actingAs($user)
            ->get(route('login.org', 'FDO'))
            ->assertRedirect();
    }

    public function test_authentification_reste_possible_quelle_que_soit_la_page_de_connexion_visitee(): void
    {
        Organization::factory()->create(['code' => 'FDO']);
        $user = User::factory()->withoutTwoFactor()->create();

        // La page /login/FDO n'affiche que le branding ; le POST reste le
        // même endpoint global (le téléphone est unique tous comptes confondus).
        $this->get(route('login.org', 'FDO'))->assertStatus(200);

        $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }
}
