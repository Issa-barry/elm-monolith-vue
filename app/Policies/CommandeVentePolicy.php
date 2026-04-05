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

    /**
     * Seuls les administrateurs (super_admin ou admin_entreprise)
     * peuvent annuler une commande.
     */
    public function annuler(User $user, CommandeVente $commande): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_entreprise'])
            && $this->sameOrganization($user, $commande);
    }

    private function sameOrganization(User $user, CommandeVente $commande): bool
    {
        return $user->organization_id === $commande->organization_id;
    }
}
