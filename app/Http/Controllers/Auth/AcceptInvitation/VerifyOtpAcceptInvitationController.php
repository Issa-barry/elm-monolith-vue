<?php

namespace App\Http\Controllers\Auth\AcceptInvitation;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use App\Services\UserInvitationService;
use App\Support\Auth\AcceptInvitationStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyOtpAcceptInvitationController extends Controller
{
    /**
     * POST /invitations/accept/{token}/otp
     * Step 2: verify the OTP code.
     */
    public function __invoke(Request $request, string $token, OtpService $otp, UserInvitationService $service): JsonResponse
    {
        $invitation = $service->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return response()->json(['error' => 'Invitation invalide ou expirée.'], 422);
        }

        $request->validate([
            'telephone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        $context = AcceptInvitationStates::otpContext($invitation);

        if ($otp->tooManyAttempts($phone, OtpPurpose::INVITATION, $context)) {
            return response()->json([
                'error' => 'Trop de tentatives. Demandez un nouveau code.',
                'reason' => 'locked',
            ], 429);
        }

        if (! $otp->hasActiveCode($phone, OtpPurpose::INVITATION, $context)) {
            return response()->json([
                'error' => 'Votre code a expiré.',
                'reason' => 'expired',
            ], 422);
        }

        if (! $otp->verify($phone, $request->input('code', ''), OtpPurpose::INVITATION, $context)) {
            return response()->json([
                'error' => 'Code incorrect.',
                'reason' => 'invalid',
            ], 422);
        }

        $otp->markVerified($phone, OtpPurpose::INVITATION, $context);

        return response()->json(['verified' => true]);
    }
}
