<?php

namespace App\Support\Auth;

/**
 * Statuts d'éligibilité à la connexion — la valeur `value` de PendingValidation et
 * Blocked/EmailNotVerified est le `code` machine renvoyé tel quel par l'API (cf.
 * LoginController, EnsureApiAccountIsActive) : ne pas la modifier sans vérifier les
 * consommateurs (mobile, futur Nuxt).
 */
enum AccountStatus: string
{
    case Ok = 'ok';
    case PendingValidation = 'pending_validation';
    case Blocked = 'account_blocked';
    case EmailNotVerified = 'email_not_verified';
}
