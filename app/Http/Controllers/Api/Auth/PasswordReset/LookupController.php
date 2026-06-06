<?php

namespace App\Http\Controllers\Api\Auth\PasswordReset;

use App\Http\Controllers\Controller;
use App\Mail\OtpPasswordResetMail;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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

        $user = User::where('telephone', $phone)->first();

        if (! $user) {
            return response()->json(['error' => 'Aucun compte trouvé pour ce numéro de téléphone.'], 404);
        }

        $code = $otp->generate($phone);

        Mail::to($user->email)->send(new OtpPasswordResetMail($code));

        return response()->json([
            'message'      => 'Un code de vérification a été envoyé à votre adresse email.',
            'masked_email' => $this->maskEmail($user->email),
        ]);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        $visible = substr($local, 0, 1);
        $masked  = $visible.str_repeat('*', max(1, strlen($local) - 1));

        return $masked.'@'.$domain;
    }
}
