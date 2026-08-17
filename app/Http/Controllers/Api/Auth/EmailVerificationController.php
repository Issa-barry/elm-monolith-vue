<?php

namespace App\Http\Controllers\Api\Auth;

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

        $identity->update([
            'verified_at' => now(),
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
