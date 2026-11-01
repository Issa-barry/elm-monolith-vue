<?php

namespace App\Support\Rh;

use App\Models\Employe;
use App\Models\User;

/**
 * Périmètre d'agence sur un salarié (ADR 0025, ADR 0034), commun à la fiche employé, aux contrats,
 * aux primes et au départ. Organisation vérifiée explicitement : le Gate::before du super
 * administrateur passe les policies. Un salarié sans agence n'est visible qu'avec la vision de
 * toutes les agences, et modifiable seulement par un profil sans restriction d'agence.
 */
final class PerimetreEmploye
{
    /** Consulter : vision de toutes les agences, sinon salarié d'une agence de rattachement. */
    public static function peutConsulter(User $user, Employe $employe): bool
    {
        if ($employe->organization_id !== $user->organization_id) {
            return false;
        }

        return $user->voitToutesLesAgences() || self::rattache($user, $employe);
    }

    /** Modifier : administrateur ou superviseur, sinon salarié d'une agence de rattachement. */
    public static function peutModifier(User $user, Employe $employe): bool
    {
        if ($employe->organization_id !== $user->organization_id) {
            return false;
        }

        return $user->agitSurToutesLesAgences() || self::rattache($user, $employe);
    }

    private static function rattache(User $user, Employe $employe): bool
    {
        return $employe->site_id !== null && $user->sites()->whereKey($employe->site_id)->exists();
    }
}
