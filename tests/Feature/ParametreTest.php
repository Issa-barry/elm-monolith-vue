<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ParametreTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'parametres.update', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('parametres.update');

        return $user;
    }

    private function userWithoutPermission(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    private function makeParametre(Organization $org, array $overrides = []): Parametre
    {
        return Parametre::create(array_merge([
            'organization_id' => $org->id,
            'cle' => 'seuil_stock_faible',
            'valeur' => '10',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_GENERAL,
            'description' => 'Seuil alerte stock faible',
        ], $overrides));
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);

        $this->actingAs($user)
            ->get(route('parametres.edit'))
            ->assertStatus(200);
    }

    public function test_edit_redirects_unauthenticated_user(): void
    {
        $this->get(route('parametres.edit'))->assertRedirect(route('login'));
    }

    public function test_edit_returns_403_without_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);

        $this->actingAs($user)
            ->get(route('parametres.edit'))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_integer_parametre_succeeds(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), [
                'valeur' => 25,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('parametres', [
            'id' => $parametre->id,
            'valeur' => '25',
        ]);
    }

    public function test_update_fails_with_invalid_integer_value(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), [
                'valeur' => 'not_a_number',
            ])
            ->assertSessionHasErrors('valeur');
    }

    public function test_update_fails_without_valeur(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), [])
            ->assertSessionHasErrors('valeur');
    }

    public function test_update_returns_403_without_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);
        $parametre = $this->makeParametre($org);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => 10])
            ->assertStatus(403);
    }

    public function test_update_returns_403_for_other_organization_parametre(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $otherOrg = Organization::factory()->create();
        $parametre = $this->makeParametre($otherOrg, ['cle' => 'prix_rouleau_defaut']);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => 999])
            ->assertStatus(403);
    }

    public function test_update_boolean_parametre_succeeds(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org, [
            'cle' => 'notifications_stock_actives',
            'valeur' => '1',
            'type' => Parametre::TYPE_BOOLEAN,
        ]);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), [
                'valeur' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('parametres', [
            'id' => $parametre->id,
            'cle' => 'notifications_stock_actives',
        ]);
    }

    public function test_update_string_parametre_succeeds(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org, [
            'cle' => 'nom_organisation',
            'valeur' => 'Ancienne valeur',
            'type' => Parametre::TYPE_STRING,
        ]);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), [
                'valeur' => 'Nouvelle valeur',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('parametres', [
            'id' => $parametre->id,
            'valeur' => 'Nouvelle valeur',
        ]);
    }

    // ── taux_proprietaire_defaut ───────────────────────────────────────────────

    public function test_update_decimal_taux_proprietaire_succeeds(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org, [
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '100',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
        ]);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => 75.50])
            ->assertRedirect();

        $this->assertDatabaseHas('parametres', [
            'id' => $parametre->id,
            'valeur' => '75.5',
        ]);
    }

    public function test_update_decimal_taux_proprietaire_fails_above_100(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org, [
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '100',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
        ]);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => 101])
            ->assertSessionHasErrors('valeur');
    }

    public function test_update_decimal_taux_proprietaire_fails_below_zero(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermission($org);
        $parametre = $this->makeParametre($org, [
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '100',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
        ]);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => -1])
            ->assertSessionHasErrors('valeur');
    }

    public function test_update_decimal_taux_proprietaire_returns_403_without_permission(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithoutPermission($org);
        $parametre = $this->makeParametre($org, [
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '100',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
        ]);

        $this->actingAs($user)
            ->put(route('parametres.update', $parametre), ['valeur' => 80])
            ->assertStatus(403);
    }

    public function test_get_taux_proprietaire_defaut_returns_float(): void
    {
        $org = Organization::factory()->create();
        Parametre::create([
            'organization_id' => $org->id,
            'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
            'valeur' => '75.50',
            'type' => Parametre::TYPE_DECIMAL,
            'groupe' => Parametre::GROUPE_VEHICULES,
            'description' => null,
        ]);

        $taux = Parametre::getTauxProprietaireDefaut($org->id);

        $this->assertSame(75.5, $taux);
    }

    public function test_get_taux_proprietaire_defaut_returns_60_when_not_set(): void
    {
        $org = Organization::factory()->create();
        Parametre::clearCache($org->id);

        $taux = Parametre::getTauxProprietaireDefaut($org->id);

        $this->assertSame(60.0, $taux);
    }
}
