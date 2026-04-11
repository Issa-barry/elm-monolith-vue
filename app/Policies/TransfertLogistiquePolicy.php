<?php

namespace App\Policies;

use App\Models\TransfertLogistique;
use App\Models\User;

class TransfertLogistiquePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('logistique.read');
    }

    public function view(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.read')
            && $this->sameOrganization($user, $transfert);
    }

    public function create(User $user): bool
    {
        return $user->can('logistique.create');
    }

    public function update(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.update')
            && $this->sameOrganization($user, $transfert)
            && $transfert->isEditable();
    }

    public function delete(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.delete')
            && $this->sameOrganization($user, $transfert)
            && $transfert->isBrouillon();
    }

    public function avancerStatut(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.update')
            && $this->sameOrganization($user, $transfert)
            && ! $transfert->isTerminal();
    }

    public function annuler(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.update')
            && $this->sameOrganization($user, $transfert)
            && ! $transfert->isTerminal();
    }

    public function genererCommission(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.commission.verser')
            && $this->sameOrganization($user, $transfert)
            && $transfert->isCloture();
    }

    public function voirCommission(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.read')
            && $this->sameOrganization($user, $transfert);
    }

    public function verserCommission(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.commission.verser')
            && $this->sameOrganization($user, $transfert)
            && $transfert->isCloture();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function sameOrganization(User $user, TransfertLogistique $transfert): bool
    {
        return $user->organization_id === $transfert->organization_id;
    }
}
