<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    public function __invoke(string $token): Response
    {
        $user = User::where('email_verification_token', $token)
            ->whereNotNull('email_verification_token')
            ->first();

        if (! $user) {
            return response()->view('emails.verify-error', ['expired' => false], 404);
        }

        if ($user->email_verification_expires_at < now()) {
            return response()->view('emails.verify-error', ['expired' => true], 410);
        }

        $user->update([
            'status' => UserStatus::ACTIVE->value,
            'is_active' => true,
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ]);

        return response()->view('emails.verified', [], 200);
    }
}
