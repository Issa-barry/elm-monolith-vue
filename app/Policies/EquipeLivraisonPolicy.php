<?php

namespace App\Policies;

use App\Models\EquipeLivraison;
use App\Models\User;

class EquipeLivraisonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('equipes-livraison.read');
    }

    public function view(User $user, EquipeLivraison $equipe): bool
    {
        return $user->can('equipes-livraison.read')
            && $user->organization_id === $equipe->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('equipes-livraison.create');
    }

    public function update(User $user, EquipeLivraison $equipe): bool
    {
        return $user->can('equipes-livraison.update')
            && $user->organization_id === $equipe->organization_id;
    }

    public function delete(User $user, EquipeLivraison $equipe): bool
    {
        return $user->can('equipes-livraison.delete')
            && $user->organization_id === $equipe->organization_id;
    }
}
