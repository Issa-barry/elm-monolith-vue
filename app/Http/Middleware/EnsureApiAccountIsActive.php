<?php

namespace App\Http\Middleware;

use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filet de sécurité API, symétrique de EnsureAccountIsActive côté web : avant ce
 * middleware, `is_active` n'était vérifié qu'au login (LoginController) — un token
 * Sanctum émis avant une désactivation de compte continuait à donner un accès
 * complet à toutes les routes `auth:sanctum` (cf. audit backend du 26/08/2026).
 *
 * Appliqué globalement sur le groupe de middleware 'api' (voir bootstrap/app.php)
 * pour ne pas dépendre de son ajout manuel sur chaque nouvelle route. Résout
 * explicitement via le guard 'sanctum' (plutôt que $request->user()) : le guard par
 * défaut de la requête n'est "promu" à sanctum qu'après le passage du middleware de
 * route `auth:sanctum`, qui s'exécute après les middlewares globaux — un simple
 * $request->user() verrait donc toujours null ici.
 */
class EnsureApiAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active && ! $user->isSuperAdmin()) {
            $status = $user->isPendingValidation() ? AccountStatus::PendingValidation : AccountStatus::Blocked;

            return response()->json([
                'message' => AccountEligibility::message($status),
                'code' => $status->value,
            ], 403);
        }

        return $next($request);
    }
}
