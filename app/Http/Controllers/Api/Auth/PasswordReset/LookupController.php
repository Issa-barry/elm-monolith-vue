<?php

namespace App\Http\Controllers\Api\Auth\PasswordReset;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        if (! User::where('telephone', $phone)->exists()) {
            // Réponse volontairement neutre pour ne pas exposer les comptes existants.
            return response()->json(['message' => 'Si ce numéro est enregistré, un code vous a été envoyé.']);
        }

        $otp->generate($phone);

        return response()->json(['message' => 'Si ce numéro est enregistré, un code vous a été envoyé.']);
    }
}
