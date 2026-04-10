<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterOtpController extends Controller
{
    /**
     * Vérifie le code OTP soumis par l'utilisateur pour le numéro de téléphone donné.
     *
     * Si le code est correct, l'OTP est marqué comme vérifié en session.
     * L'étape 2 du formulaire d'inscription peut alors être affichée côté client.
     */
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:5'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json([
                'error' => 'Numéro de téléphone invalide.',
            ], 422);
        }

        if (! $otp->verify($phone, $request->input('code', ''))) {
            return response()->json([
                'error' => 'Code de vérification incorrect.',
            ], 422);
        }

        $otp->markVerified($phone);

        return response()->json(['verified' => true]);
    }
}
