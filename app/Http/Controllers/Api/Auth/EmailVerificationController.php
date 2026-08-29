<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\OtpChannel;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\UserAuthIdentity;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    public function __invoke(string $token): Response
    {
        $identity = UserAuthIdentity::where('verification_token', $token)
            ->whereNotNull('verification_token')
            ->first();

        if (! $identity) {
            return response()->view('emails.verify-error', ['expired' => false], 404);
        }

        if ($identity->verification_expires_at < now()) {
            return response()->view('emails.verify-error', ['expired' => true], 410);
        }

        // Lien de vérification reçu par email : preuve réelle de possession de
        // cette adresse — markVerifiedVia() applique la même règle que le reste
        // du système OTP (cf. rapport du 27/08/2026), même si ce mécanisme
        // (lien signé) est distinct d'OtpService.
        $identity->markVerifiedVia(OtpChannel::EMAIL);
        $identity->update([
            'verification_token' => null,
            'verification_expires_at' => null,
        ]);

        $identity->user->update([
            'status' => UserStatus::ACTIVE->value,
            'is_active' => true,
        ]);

        return response()->view('emails.verified', [], 200);
    }
}
