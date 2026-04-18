<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
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
            ? new JsonResponse('', 201)
            : redirect()->intended($redirectTo);
    }
}
