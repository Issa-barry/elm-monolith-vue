<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Réponse 429 uniforme pour tout envoi/renvoi de code OTP bloqué par une limite anti-spam
 * (cf. App\Services\OtpService::resendWaitSeconds()) — ne précise jamais laquelle des limites
 * exactes a été atteinte (cooldown, plafond horaire ou journalier), seulement le délai d'attente.
 * Partagé par AcceptInvitationController (OTP téléphone) et InstallWizardController (OTP email) :
 * même contrat de réponse, indépendant de ce qui est vérifié.
 */
trait HasOtpRateLimitResponse
{
    private function tooManyRequestsResponse(int $waitSeconds): JsonResponse
    {
        $minutes = max(1, (int) ceil($waitSeconds / 60));

        return response()->json([
            'error' => "Vous avez demandé trop de codes. Réessayez dans {$minutes} minute".($minutes > 1 ? 's' : '').'.',
            'retry_after_seconds' => $waitSeconds,
        ], 429);
    }
}
