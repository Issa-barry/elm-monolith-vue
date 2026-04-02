<?php

namespace App\Http\Middleware;

use App\Services\ModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModuleEnabled
{
    /**
     * Bloque l'accès à la route si le module est désactivé pour l'organisation.
     *
     * Usage dans les routes : ->middleware('module:module.ventes')
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        // Charge l'organisation si ce n'est pas déjà fait (idempotent)
        $org = $user->relationLoaded('organization')
            ? $user->organization
            : $user->load('organization')->organization;

        if (! $org) {
            abort(403, 'Organisation introuvable.');
        }

        if (! ModuleService::isActive($module, $org)) {
            abort(403, 'Ce module est désactivé pour votre organisation.');
        }

        return $next($request);
    }
}
