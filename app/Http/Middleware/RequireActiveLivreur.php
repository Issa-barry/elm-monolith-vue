<?php

namespace App\Http\Middleware;

use App\Models\Livreur;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveLivreur
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('livreur')) {
            return $next($request);
        }

        // Livreur qui est aussi propriétaire actif → accès complet
        if ($user->hasRole('proprietaire')) {
            return $next($request);
        }

        $livreur = Livreur::where('user_id', $user->id)->first();
        $isActive = ! $livreur || $livreur->is_active;

        // Livreur actif sur la page "pending" → rediriger vers le dashboard
        if ($request->routeIs('client.pending')) {
            return $isActive
                ? redirect()->route('client.dashboard')
                : $next($request);
        }

        return $isActive ? $next($request) : redirect()->route('client.pending');
    }
}
