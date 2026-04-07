<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RequireSiteAssigned
{
    /**
     * Bloque tout utilisateur staff qui n'est affecté à aucun site.
     * Les super_admins sont exemptés (ils gèrent tous les sites).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Laisser passer si non authentifié, super admin, ou déjà affecté à un site.
        if (! $user || $user->hasRole('super_admin') || $user->sites()->exists()) {
            return $next($request);
        }

        return Inertia::render('Errors/SiteRequired', [
            'user_name' => $user->name,
        ])->toResponse($request)->setStatusCode(403);
    }
}
