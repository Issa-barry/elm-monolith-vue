<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();

        if ($user?->hasAnyRole(['client', 'proprietaire', 'livreur'])) {
            $redirectTo = route('client.dashboard');
        } elseif ($user?->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'])) {
            $redirectTo = route('dashboard');
        } else {
            $redirectTo = route('home');
        }

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($redirectTo);
    }
}
