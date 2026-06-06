<?php

namespace App\Http\Controllers\Api\Auth\PasswordReset;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ResetController extends Controller
{
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::default()],
            'password_confirmation' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            throw ValidationException::withMessages(['telephone' => 'Numéro de téléphone invalide.']);
        }

        if (! $otp->isVerified($phone)) {
            throw ValidationException::withMessages(['telephone' => 'La vérification OTP est requise avant de réinitialiser le mot de passe.']);
        }

        $user = User::where('telephone', $phone)->first();

        if (! $user) {
            throw ValidationException::withMessages(['telephone' => 'Aucun compte trouvé pour ce numéro.']);
        }

        $user->forceFill(['password' => $request->input('password')])->save();

        $otp->clear($phone);

        // Révoquer tous les tokens existants pour forcer une reconnexion.
        $user->tokens()->delete();

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }
}
