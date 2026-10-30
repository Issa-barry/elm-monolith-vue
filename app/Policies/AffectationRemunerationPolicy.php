<?php

namespace App\Policies;

use App\Models\AffectationRemuneration;
use App\Models\User;

/**
 * Permissions de base des affectations de primes (ADR 0039). Le droit de validation, l'organisation
 * et l'agence du salarié sont revérifiés par AffectationRemunerationService (le Gate::before du
 * super administrateur court-circuite cette policy).
 */
class AffectationRemunerationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-affectations-remuneration.read');
    }

    public function view(User $user, AffectationRemuneration $affectation): bool
    {
        return $user->can('rh-affectations-remuneration.read')
            && $user->organization_id === $affectation->organization_id;
    }

    /** Attribuer, proposer un changement de montant, saisir le montant d'un mois. */
    public function create(User $user): bool
    {
        return $user->can('rh-affectations-remuneration.create');
    }

    /** Modifier une proposition, demander ou retirer une fin d'effet. */
    public function update(User $user, AffectationRemuneration $affectation): bool
    {
        return $user->can('rh-affectations-remuneration.update')
            && $user->organization_id === $affectation->organization_id;
    }

    /** Annuler une proposition (une affectation validée exige en plus le droit de validation). */
    public function delete(User $user, AffectationRemuneration $affectation): bool
    {
        return $user->can('rh-affectations-remuneration.delete')
            && $user->organization_id === $affectation->organization_id;
    }

    public function valider(User $user, AffectationRemuneration $affectation): bool
    {
        return $user->can('rh-affectations-remuneration.valider')
            && $user->organization_id === $affectation->organization_id;
    }
}
