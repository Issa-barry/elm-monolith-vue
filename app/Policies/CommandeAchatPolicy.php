<?php

namespace App\Policies;

use App\Models\CommandeAchat;
use App\Models\User;

class CommandeAchatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('achats.read');
    }

    public function view(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.read')
            && $this->sameOrganization($user, $commande);
    }

    public function create(User $user): bool
    {
        return $user->can('achats.create');
    }

    public function update(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.update')
            && $this->sameOrganization($user, $commande);
    }

    public function delete(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.delete')
            && $this->sameOrganization($user, $commande);
    }

    private function sameOrganization(User $user, CommandeAchat $commande): bool
    {
        return $user->organization_id === $commande->organization_id;
    }
}
