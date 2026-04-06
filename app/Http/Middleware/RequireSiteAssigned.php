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

        // Pas encore authentifié : laisser passer
        if (! $user) {
            return $next($request);
        }

        // Super admin : pas de restriction de site
        if ($user->hasRole('super_admin')) {
            return $next($request);
        }

        // Vérifie si l'utilisateur a au moins 1 site affecté
        $hasSite = $user->sites()->exists();

        if (! $hasSite) {
            return Inertia::render('Errors/SiteRequired', [
                'user_name' => $user->name,
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
