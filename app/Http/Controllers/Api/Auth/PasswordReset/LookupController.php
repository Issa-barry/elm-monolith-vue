<?php

namespace App\Http\Controllers\Api\Auth\PasswordReset;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Mail\OtpPasswordResetMail;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\Otp\OtpDestinationMasker;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class LookupController extends Controller
{
    public function __invoke(Request $request, OtpService $otp, OtpDestinationMasker $masker): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        $user = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone));

        if (! $user) {
            return response()->json(['error' => 'Aucun compte trouvé pour ce numéro de téléphone.'], 404);
        }

        if (! $otp->canSend($phone)) {
            return response()->json(['error' => 'Trop de demandes de code. Veuillez réessayer plus tard.'], 429);
        }

        $code = $otp->generate($phone, OtpPurpose::PASSWORD_RESET);

        Mail::to($user->email)->send(new OtpPasswordResetMail($code));

        return response()->json([
            'message' => 'Un code de vérification a été envoyé à votre adresse email.',
            'masked_email' => $masker->mask(OtpChannel::EMAIL, $user->email),
        ]);
    }
}
