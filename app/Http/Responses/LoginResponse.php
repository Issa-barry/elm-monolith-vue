<?php

namespace App\Http\Responses;

use App\Support\AuthRedirects;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $redirectTo = AuthRedirects::resolvePostAuthRedirect($request, $request->user());

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->to($redirectTo);
    }
}
