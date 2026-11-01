<?php

namespace App\Policies;

use App\Models\TypeRemuneration;
use App\Models\User;

/**
 * Permissions de base du référentiel des primes et avantages. Le droit de validation, les
 * transitions de statut et l'organisation sont contrôlés par TypeRemunerationService (le
 * Gate::before du super administrateur court-circuite cette policy).
 */
class TypeRemunerationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-types-remuneration.read');
    }

    public function view(User $user, TypeRemuneration $type): bool
    {
        return $user->can('rh-types-remuneration.read') && $user->organization_id === $type->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('rh-types-remuneration.create');
    }

    public function update(User $user, TypeRemuneration $type): bool
    {
        return $user->can('rh-types-remuneration.update') && $user->organization_id === $type->organization_id;
    }

    public function delete(User $user, TypeRemuneration $type): bool
    {
        return $user->can('rh-types-remuneration.delete') && $user->organization_id === $type->organization_id;
    }

    /** Valider, fermer, désactiver, réactiver : permission dédiée, par défaut au seul super administrateur. */
    public function valider(User $user, TypeRemuneration $type): bool
    {
        return $user->can('rh-types-remuneration.valider') && $user->organization_id === $type->organization_id;
    }
}
