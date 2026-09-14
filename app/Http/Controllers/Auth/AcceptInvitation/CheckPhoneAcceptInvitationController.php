<?php

namespace App\Http\Controllers\Auth\AcceptInvitation;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Concerns\HasOtpRateLimitResponse;
use App\Http\Controllers\Controller;
use App\Mail\OtpInvitationMail;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use App\Services\UserInvitationService;
use App\Support\Auth\AcceptInvitationStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class CheckPhoneAcceptInvitationController extends Controller
{
    use HasOtpRateLimitResponse;

    /**
     * POST /invitations/accept/{token}/phone
     * Step 1: look up the submitted phone number.
     * Returns status + optional prefill (same contract as /register/lookup).
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

        if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone)) !== null) {
            return response()->json(['status' => 'user_exists']);
        }

        $context = AcceptInvitationStates::otpContext($invitation);

        $wait = $otp->resendWaitSeconds($phone, $context);
        if ($wait > 0) {
            return $this->tooManyRequestsResponse($wait);
        }

        $prefill = $service->phonePrefill($phone);
        $code = $otp->generate($phone, OtpPurpose::INVITATION, $context);

        Mail::to($invitation->email)->send(new OtpInvitationMail($code));

        return response()->json([
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
            'cooldown_seconds' => $otp->resendCooldownSeconds(),
        ]);
    }
}
