<?php

namespace App\Http\Responses;

use App\Support\AuthRedirects;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        $redirectTo = AuthRedirects::resolvePostAuthRedirect($request, $request->user());

        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->to($redirectTo);
    }
}
