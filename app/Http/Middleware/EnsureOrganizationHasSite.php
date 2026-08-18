<?php

namespace App\Http\Middleware;

use App\Support\AuthRedirects;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empêche un utilisateur staff dont l'ORGANISATION n'a encore aucun site (onboarding jamais
 * terminé — le premier site n'est plus créé pendant /install, cf. InstallationService) d'entrer
 * dans le back-office par n'importe quelle porte : accès direct, lien profond, onglet rouvert
 * après interruption... Pas seulement juste après la connexion, contrairement à
 * AuthRedirects::resolvePostAuthRedirect() (qui, lui, ne s'exécute qu'au moment de l'authentification
 * et pouvait être court-circuité par une "intended URL" mémorisée en session AVANT l'installation —
 * cf. rapport de bug "Aucun site affecté"). Réutilise AuthRedirects::needsOnboarding() plutôt que
 * de refaire sa propre requête : source de vérité unique, jamais deux définitions contradictoires
 * de "cette organisation a-t-elle besoin de l'onboarding ?".
 *
 * Volontairement distincte de RequireSiteAssigned (qui vérifie que CET utilisateur précis est
 * personnellement affecté à un site, et exempte le super_admin qui gère tous les sites sans avoir
 * besoin d'y être assigné) : ici, c'est l'ORGANISATION elle-même qui n'a encore AUCUN site, un état
 * plus radical qui concerne tous les rôles staff sans exception, super_admin compris — lui seul
 * peut d'ailleurs créer ce premier site (cf. OnboardingSiteController).
 *
 * Alias : org.site.required (cf. bootstrap/app.php). Ne JAMAIS appliquer aux routes /onboarding/*
 * elles-mêmes (boucle de redirection garantie).
 */
class EnsureOrganizationHasSite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AuthRedirects::needsOnboarding($request->user())) {
            return redirect()->route('onboarding.site.show');
        }

        return $next($request);
    }
}
