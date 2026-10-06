<?php

namespace App\Support\Auth;

use App\Models\UserInvitation;

/**
 * États d'erreur affichés par la page d'onboarding via lien d'invitation
 * (`Auth\AcceptInvitation\*`) — extrait de l'ancien `AcceptInvitationController`, partagé entre
 * l'affichage de la page et l'étape finale de création de compte.
 */
final class AcceptInvitationStates
{
    /**
     * Lie l'OTP à cette invitation précise (id + email) : un même numéro de téléphone
     * réinvité ne peut jamais réutiliser/hériter d'un code généré pour une autre invitation.
     */
    public static function otpContext(UserInvitation $invitation): string
    {
        return $invitation->id.'|'.$invitation->email;
    }

    public static function invitationErrorState(?UserInvitation $invitation): ?string
    {
        return match (true) {
            $invitation === null => 'not_found',
            $invitation->isAccepted() => 'already_accepted',
            $invitation->isRevoked() => 'revoked',
            $invitation->isExpired() => 'expired',
            default => null,
        };
    }
}
