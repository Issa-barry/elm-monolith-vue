<?php

namespace App\Policies;

use App\Models\Prestataire;
use App\Models\User;

class PrestatairePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('prestataires.read');
    }

    public function view(User $user, Prestataire $prestataire): bool
    {
        return $user->can('prestataires.read')
            && $this->sameOrganization($user, $prestataire);
    }

    public function create(User $user): bool
    {
        return $user->can('prestataires.create');
    }

    public function update(User $user, Prestataire $prestataire): bool
    {
        return $user->can('prestataires.update')
            && $this->sameOrganization($user, $prestataire);
    }

    public function delete(User $user, Prestataire $prestataire): bool
    {
        return $user->can('prestataires.delete')
            && $this->sameOrganization($user, $prestataire);
    }

    private function sameOrganization(User $user, Prestataire $prestataire): bool
    {
        return $user->organization_id === $prestataire->organization_id;
    }
}
