<?php

namespace App\Policies;

use App\Enums\StatutPeriodePaie;
use App\Models\PaiePeriode;
use App\Models\User;

class PaiePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-paie.read');
    }

    public function view(User $user, PaiePeriode $periode): bool
    {
        return $user->can('rh-paie.read')
            && $user->organization_id === $periode->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('rh-paie.create');
    }

    public function update(User $user, PaiePeriode $periode): bool
    {
        return $user->can('rh-paie.update')
            && $user->organization_id === $periode->organization_id;
    }

    public function validate(User $user, PaiePeriode $periode): bool
    {
        return $user->can('rh-paie.validate')
            && $user->organization_id === $periode->organization_id;
    }

    public function pay(User $user, PaiePeriode $periode): bool
    {
        return $user->can('rh-paie.pay')
            && $user->organization_id === $periode->organization_id
            && in_array($periode->statut, [StatutPeriodePaie::VALIDE_RH, StatutPeriodePaie::PAYE], true);
    }

    public function close(User $user, PaiePeriode $periode): bool
    {
        return $user->can('rh-paie.close')
            && $user->organization_id === $periode->organization_id;
    }

    public function delete(User $user, PaiePeriode $periode): bool
    {
        return $user->can('rh-paie.delete')
            && $user->organization_id === $periode->organization_id
            && ! $periode->statut->estVerrouille();
    }
}
