<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait HasAdminSetup
{
    private function makeAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $this->attachDefaultSite($org, $user);

        return $user;
    }

    // Ne rattache PAS de site par défaut ici (contrairement à makeAdminUser ci-dessous) : cette
    // méthode est aussi appelée par HasOrgAndUser::initOrgAndUser(), qui crée déjà son propre
    // site juste après — en ajouter un second ici créerait un doublon (deux sites pour la même
    // organisation, cf. Site Principal/Conakry référencés par de nombreux tests).
    private function makeUserWithPermissions(Organization $org, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);

        return $user;
    }

    // Une organisation sans aucun site force l'onboarding pour tout rôle staff, super_admin
    // compris (cf. AuthRedirects::needsOnboarding, middleware EnsureOrganizationHasSite) — sans
    // ce site, toute requête back-office de ces helpers est redirigée avant même d'atteindre le
    // contrôleur testé.
    private function attachDefaultSite(Organization $org, User $user): void
    {
        if (Site::where('organization_id', $org->id)->exists()) {
            return;
        }

        $site = Site::factory()->for($org)->create();
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }
}
