<?php

namespace Tests\Feature;

use App\Enums\DomaineActivite;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Onboarding du premier site — plus créé pendant /install (cf. InstallationService), une
 * organisation fraîche n'a donc aucun site. La redirection vers l'onboarding se décide dans
 * AuthRedirects::defaultPathForUser() (login, changement de mot de passe forcé, `/`), pas via un
 * middleware sur /backoffice/dashboard — cf. docblock de la classe pour pourquoi.
 */
class OnboardingSiteTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(?DomaineActivite $domaine = DomaineActivite::COMMERCE_DISTRIBUTION): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sites.create', 'guard_name' => 'web']);
        $org = Organization::create([
            'name' => 'ELM Test', 'slug' => 'elm-test', 'is_active' => true,
            'domaine_activite' => $domaine,
        ]);
        $user = User::factory()->withoutTwoFactor()->create([
            'organization_id' => $org->id,
            'password' => Hash::make('Sup3r$ecretPwd'),
        ]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_connexion_redirige_vers_onboarding_quand_aucun_site(): void
    {
        $user = $this->makeSuperAdmin();

        $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'Sup3r$ecretPwd',
        ])->assertRedirect(route('onboarding.site.show'));
    }

    public function test_connexion_redirige_vers_dashboard_quand_un_site_existe_deja(): void
    {
        $user = $this->makeSuperAdmin();
        Site::create(['organization_id' => $user->organization_id, 'nom' => 'Siège', 'type' => 'siege']);

        $this->post(route('login.store'), [
            'telephone' => $user->telephone,
            'password' => 'Sup3r$ecretPwd',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_onboarding_propose_les_types_suggeres_par_le_domaine(): void
    {
        $user = $this->makeSuperAdmin(DomaineActivite::RESTAURATION);

        $this->actingAs($user)
            ->get(route('onboarding.site.show'))
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Site')
                ->where('types_suggeres', fn ($types) => collect($types)->pluck('value')->all() === ['siege', 'restaurant', 'depot'])
            );
    }

    public function test_onboarding_redirige_vers_dashboard_si_un_site_existe_deja(): void
    {
        $user = $this->makeSuperAdmin();
        Site::create(['organization_id' => $user->organization_id, 'nom' => 'Siège', 'type' => 'siege']);

        $this->actingAs($user)
            ->get(route('onboarding.site.show'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_store_cree_le_site_et_lattache_comme_site_par_defaut(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->post(route('onboarding.site.store'), [
                'nom' => 'Siège', 'type' => SiteType::SIEGE->value,
                'ville' => 'Conakry', 'quartier' => 'Matoto',
            ])
            ->assertRedirect(route('dashboard'));

        $site = Site::where('organization_id', $user->organization_id)->firstOrFail();
        $this->assertSame('Siège', $site->nom);
        $this->assertSame(SiteType::SIEGE, $site->type);

        $pivot = $user->sites()->wherePivot('is_default', true)->first();
        $this->assertNotNull($pivot);
        $this->assertSame($site->id, $pivot->id);
    }

    public function test_store_refuse_un_type_de_site_invalide(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->post(route('onboarding.site.store'), [
                'nom' => 'Siège', 'type' => 'pas-un-type',
                'ville' => 'Conakry', 'quartier' => 'Matoto',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_store_refuse_si_un_site_existe_deja(): void
    {
        $user = $this->makeSuperAdmin();
        Site::create(['organization_id' => $user->organization_id, 'nom' => 'Siège', 'type' => 'siege']);

        $this->actingAs($user)
            ->post(route('onboarding.site.store'), [
                'nom' => 'Autre', 'type' => SiteType::AGENCE->value,
                'ville' => 'Conakry', 'quartier' => 'Matoto',
            ])
            ->assertStatus(403);

        $this->assertSame(1, Site::where('organization_id', $user->organization_id)->count());
    }
}
