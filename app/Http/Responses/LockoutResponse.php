<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class LockoutResponse implements LockoutResponseContract
{
    public function __construct(protected LoginRateLimiter $limiter) {}

    /**
     * Message en dur (comme le reste du flux d'auth dans FortifyServiceProvider) :
     * l'implémentation par défaut de Fortify utilise trans('auth.throttle'), qui
     * dépend de la résolution de APP_LOCALE côté serveur. Le login ne doit jamais
     * afficher de message en anglais.
     */
    public function toResponse($request)
    {
        return with($this->limiter->availableIn($request), function ($seconds) {
            throw ValidationException::withMessages([
                Fortify::username() => [
                    'Trop de tentatives. Réessayez dans '.$seconds.' secondes.',
                ],
            ])->status(429);
        });
    }
}
