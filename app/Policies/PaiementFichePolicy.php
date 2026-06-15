<?php

namespace App\Policies;

use App\Models\PaiementFiche;
use App\Models\User;

class PaiementFichePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('comptabilite.read');
    }

    public function view(User $user, PaiementFiche $fiche): bool
    {
        if ($user->organization_id !== $fiche->organization_id) {
            return false;
        }

        if (! $user->can('comptabilite.read')) {
            return false;
        }

        return $user->isAdmin() || $this->sameSite($user, $fiche);
    }

    public function payer(User $user, PaiementFiche $fiche): bool
    {
        if ($user->organization_id !== $fiche->organization_id) {
            return false;
        }

        if (! $user->can('comptabilite.payer')) {
            return false;
        }

        return $user->isAdmin() || $this->sameSite($user, $fiche);
    }

    private function sameSite(User $user, PaiementFiche $fiche): bool
    {
        if ($fiche->site_id === null) {
            return true;
        }

        return $user->isAssignedToSite($fiche->site_id);
    }
}
