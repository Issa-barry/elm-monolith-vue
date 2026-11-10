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

        // Lecture : l'agence qui paie et l'agence de rattachement (ADR 0061).
        return $user->voitToutesLesAgences()
            || $this->sameSite($user, $fiche)
            || ($fiche->site_rattachement_id !== null && $user->isAssignedToSite($fiche->site_rattachement_id));
    }

    public function payer(User $user, PaiementFiche $fiche): bool
    {
        if ($user->organization_id !== $fiche->organization_id) {
            return false;
        }

        if (! $user->can('comptabilite.payer')) {
            return false;
        }

        // Paiement : seule l'agence qui paie (`site_id`), jamais l'agence de rattachement.
        return $user->agitSurToutesLesAgences() || $this->sameSite($user, $fiche);
    }

    private function sameSite(User $user, PaiementFiche $fiche): bool
    {
        if ($fiche->site_id === null) {
            return true;
        }

        return $user->isAssignedToSite($fiche->site_id);
    }
}
