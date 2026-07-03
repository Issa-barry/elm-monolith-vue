<?php

namespace App\Policies;

use App\Models\PaiementPeriode;
use App\Models\User;

class PaiementPeriodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('comptabilite.read');
    }

    public function view(User $user, PaiementPeriode $periode): bool
    {
        return $user->can('comptabilite.read')
            && $user->organization_id === $periode->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function calculer(User $user, PaiementPeriode $periode): bool
    {
        return $user->isAdmin()
            && $user->organization_id === $periode->organization_id
            && $periode->peutEtreCalculee();
    }

    public function valider(User $user, PaiementPeriode $periode): bool
    {
        return $user->isAdmin()
            && $user->organization_id === $periode->organization_id
            && $periode->peutEtreValidee();
    }

    public function ajuster(User $user, PaiementPeriode $periode): bool
    {
        return $user->isAdmin()
            && $user->organization_id === $periode->organization_id
            && $periode->isCalculee();
    }

    public function cloturer(User $user, PaiementPeriode $periode): bool
    {
        return $user->isAdmin()
            && $user->organization_id === $periode->organization_id
            && $periode->peutEtreCloturee();
    }

    public function delete(User $user, PaiementPeriode $periode): bool
    {
        return $user->isAdmin()
            && $user->organization_id === $periode->organization_id
            && $periode->isBrouillon();
    }
}
