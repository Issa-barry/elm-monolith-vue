<?php

namespace App\Policies;

use App\Models\CommandeVente;
use App\Models\User;

class CommandeVentePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ventes.read');
    }

    public function view(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.read')
            && $this->sameOrganization($user, $commande);
    }

    public function create(User $user): bool
    {
        return $user->can('ventes.create');
    }

    public function update(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande);
    }

    public function delete(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.delete')
            && $this->sameOrganization($user, $commande);
    }

    private function sameOrganization(User $user, CommandeVente $commande): bool
    {
        return $user->organization_id === $commande->organization_id;
    }
}
