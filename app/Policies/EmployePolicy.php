<?php

namespace App\Policies;

use App\Models\Employe;
use App\Models\User;

class EmployePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-employes.read');
    }

    public function view(User $user, Employe $employe): bool
    {
        return $user->can('rh-employes.read')
            && $user->organization_id === $employe->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('rh-employes.create');
    }

    public function update(User $user, Employe $employe): bool
    {
        return $user->can('rh-employes.update')
            && $user->organization_id === $employe->organization_id;
    }

    public function delete(User $user, Employe $employe): bool
    {
        return $user->can('rh-employes.delete')
            && $user->organization_id === $employe->organization_id;
    }
}
