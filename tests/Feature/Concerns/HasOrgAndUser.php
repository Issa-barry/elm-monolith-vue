<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;

trait HasOrgAndUser
{
    private Organization $org;

    private User $user;

    protected function initOrgAndUser(array $permissions): void
    {
        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, $permissions);

        // Attacher un site par défaut pour passer le middleware RequireSiteAssigned
        $site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        // Types de véhicules par défaut — pure classification, aucune capacité (cf.
        // VehiculeCapaciteService : la capacité appartient exclusivement au véhicule).
        foreach (['Camion', 'Minibus', 'Tricycle'] as $nom) {
            TypeVehicule::create([
                'organization_id' => $this->org->id,
                'nom' => $nom,
                'is_active' => true,
            ]);
        }
    }
}
