<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Miroir de App\Http\Controllers\Auth\RegisterOtpController pour l'app vitrine —
 * appelée server-to-server, jamais directement par un navigateur.
 */
class RegisterOtpController extends Controller
{
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json([
                'error' => 'Numéro de téléphone invalide.',
            ], 422);
        }

        if ($otp->tooManyAttempts($phone)) {
            return response()->json([
                'error' => 'Trop de tentatives. Demandez un nouveau code.',
            ], 429);
        }

        if (! $otp->verify($phone, $request->input('code', ''))) {
            return response()->json([
                'error' => 'Code incorrect ou expiré.',
            ], 422);
        }

        $otp->markVerified($phone);

        return response()->json(['verified' => true]);
    }
}
