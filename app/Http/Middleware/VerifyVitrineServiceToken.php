<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège les routes api/public/* : elles n'ont ni session ni CSRF (routes/api.php)
 * et sont appelées uniquement en server-to-server par l'app vitrine, jamais
 * directement par le navigateur d'un visiteur. Ce header partagé évite qu'elles
 * soient une surface d'écriture ouverte à quiconque lit le JS/HTML public.
 */
class VerifyVitrineServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.vitrine.token');

        if (! $expected || ! hash_equals($expected, (string) $request->header('X-Vitrine-Key'))) {
            abort(403, 'Accès refusé.');
        }

        return $next($request);
    }
}
