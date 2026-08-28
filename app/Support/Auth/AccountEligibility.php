<?php

namespace App\Support\Auth;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Règle unique d'éligibilité à la connexion/à l'accès, centralisée pour éviter la
 * divergence constatée entre LoginController (API), FortifyServiceProvider::
 * authenticateUsing (web) et EnsureAccountIsActive (filet de sécurité web) : les
 * trois réimplémentaient `is_active`/`isPendingValidation`/`hasVerifiedEmail` avec
 * des messages légèrement différents (ex: "bloqué" côté API vs "désactivé" côté web
 * pour exactement le même état — cf. audit backend du 26/08/2026).
 */
class AccountEligibility
{
    public static function status(User $user): AccountStatus
    {
        if (! $user->is_active && ! $user->isSuperAdmin()) {
            // User::STATUS_PENDING_VALIDATION ('pending_validation', flux invitation/
            // validation admin) prime toujours sur l'email : le vérifier ne débloquerait
            // rien, seul un admin peut valider — cf.
            // AuthenticationTest::test_pending_validation_message_takes_priority_over_unverified_email.
            if ($user->isPendingValidation()) {
                return AccountStatus::PendingValidation;
            }

            // UserStatus::PENDING ('pending', tout autre statut que ci-dessus) est en
            // revanche exactement ce que pose RegistrationService::register() tant que
            // l'email n'est pas confirmé — un blocage auto-résolu par la vérification,
            // à ne pas confondre avec une désactivation admin (Blocked).
            if ($user->status === UserStatus::PENDING->value && ! $user->hasVerifiedEmail() && ! $user->isSuperAdmin()) {
                return AccountStatus::EmailNotVerified;
            }

            return AccountStatus::Blocked;
        }

        if (! $user->hasVerifiedEmail() && ! $user->isSuperAdmin()) {
            return AccountStatus::EmailNotVerified;
        }

        return AccountStatus::Ok;
    }

    public static function message(AccountStatus $status): string
    {
        return match ($status) {
            AccountStatus::PendingValidation => 'Votre compte a bien été créé. Il est en attente de validation par un administrateur.',
            AccountStatus::Blocked => 'Votre compte a été désactivé. Veuillez contacter notre service client pour plus d\'informations.',
            AccountStatus::EmailNotVerified => 'Veuillez vérifier votre adresse email pour activer votre compte. Consultez votre boîte de réception.',
            AccountStatus::Ok => '',
        };
    }
}
