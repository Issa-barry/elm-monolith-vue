<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque l'accès au back-office tant qu'un compte créé avec un mot de passe provisoire
 * (`must_change_password`, cf. migration + InstallApp) n'en a pas défini un lui-même — même
 * s'il connaît déjà le mot de passe provisoire (ex: super admin créé par `php artisan
 * app:install`, dont l'opérateur qui a lancé la commande connaît aussi le secret).
 */
class EnsurePasswordIsNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('password.force-change*') || $request->routeIs('logout')) {
            return $next($request);
        }

        return redirect()->route('password.force-change');
    }
}
