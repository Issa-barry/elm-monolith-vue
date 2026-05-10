<?php

namespace App\Policies;

use App\Models\PropositionVehicule;
use App\Models\User;

class PropositionVehiculePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('propositions.read');
    }

    public function view(User $user, PropositionVehicule $proposition): bool
    {
        return $user->can('propositions.read')
            && $user->organization_id === $proposition->organization_id;
    }

    public function update(User $user, PropositionVehicule $proposition): bool
    {
        return $user->can('propositions.update')
            && $user->organization_id === $proposition->organization_id
            && ! ($proposition->statut?->isTerminal() ?? false);
    }
}
