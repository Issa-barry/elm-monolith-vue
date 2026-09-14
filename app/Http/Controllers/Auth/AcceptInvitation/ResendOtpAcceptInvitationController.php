<?php

namespace App\Http\Controllers\Auth\AcceptInvitation;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Concerns\HasOtpRateLimitResponse;
use App\Http\Controllers\Controller;
use App\Mail\OtpInvitationMail;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use App\Services\UserInvitationService;
use App\Support\Auth\AcceptInvitationStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ResendOtpAcceptInvitationController extends Controller
{
    use HasOtpRateLimitResponse;

    /**
     * POST /invitations/accept/{token}/otp/resend
     * Renvoie un nouveau code : invalide l'ancien, réinitialise les tentatives,
     * envoie un nouvel email. Toujours disponible côté UI, mais soumis aux mêmes
     * limites anti-spam (cooldown + plafonds horaire/journalier) que l'envoi initial.
     */
    public function __invoke(Request $request, string $token, OtpService $otp, UserInvitationService $service): JsonResponse
    {
        $invitation = $service->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['error' => 'Invitation invalide ou expirée.'], 422);
        }

        $request->validate(['telephone' => ['required', 'string']]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        $context = AcceptInvitationStates::otpContext($invitation);

        $wait = $otp->resendWaitSeconds($phone, $context);
        if ($wait > 0) {
            return $this->tooManyRequestsResponse($wait);
        }

        $code = $otp->generate($phone, OtpPurpose::INVITATION, $context);

        Mail::to($invitation->email)->send(new OtpInvitationMail($code));

        return response()->json([
            'resent' => true,
            'cooldown_seconds' => $otp->resendCooldownSeconds(),
        ]);
    }
}
