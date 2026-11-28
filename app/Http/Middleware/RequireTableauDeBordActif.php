<?php

namespace App\Http\Middleware;

use App\Services\TableauDeBordService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTableauDeBordActif
{
    /**
     * Refuse la route d'un tableau de bord désactivé pour l'organisation (ADR 0071), avant le
     * contrôleur : aucun calcul n'est lancé. S'ajoute au middleware `module:` et à la permission
     * vérifiée par le contrôleur.
     *
     * Usage : ->middleware('tableau-de-bord:'.TableauDeBordFeature::STOCK)
     */
    public function handle(Request $request, Closure $next, string $tableau): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $org = $user->loadMissing('organization')->organization;

        if (! $org) {
            abort(403, 'Organisation introuvable.');
        }

        if (! TableauDeBordService::isActive($tableau, $org)) {
            abort(403, 'Ce tableau de bord est désactivé pour votre organisation.');
        }

        return $next($request);
    }
}
