<?php

namespace App\Policies;

use App\Models\TransmissionPaie;
use App\Models\User;

/**
 * Droits séparés de la transmission de paie (ADR 0040) : consulter, préparer, valider, transmettre.
 * Revérifiés, avec l'organisation et le périmètre d'agences, par TransmissionPaieService.
 */
class TransmissionPaiePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rh-transmissions-paie.read');
    }

    public function view(User $user, TransmissionPaie $transmission): bool
    {
        return $user->can('rh-transmissions-paie.read')
            && $user->organization_id === $transmission->organization_id;
    }

    public function preparer(User $user): bool
    {
        return $user->can('rh-transmissions-paie.preparer');
    }

    public function valider(User $user, TransmissionPaie $transmission): bool
    {
        return $user->can('rh-transmissions-paie.valider')
            && $user->organization_id === $transmission->organization_id;
    }

    public function transmettre(User $user, TransmissionPaie $transmission): bool
    {
        return $user->can('rh-transmissions-paie.transmettre')
            && $user->organization_id === $transmission->organization_id;
    }
}
