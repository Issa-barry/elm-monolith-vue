<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empêche tout cache intermédiaire (CDN d'hébergement, proxy, navigateur) de
 * mémoriser une réponse dynamique et de la resservir à une autre requête — y
 * compris une réponse JSON Inertia (`X-Inertia: true`) resservie brute à une
 * navigation navigateur normale.
 *
 * Incident 2026-08-25 : un GET direct sur /backoffice/achats/{id} (et
 * /backoffice/type-vehicules/{id}/edit) affichait le payload JSON Inertia
 * complet au lieu du HTML/Vue. `inertiajs/inertia-laravel` positionne bien
 * `Vary: X-Inertia` sur chaque réponse (cf. vendor Middleware.php) pour que la
 * variante JSON (souvent sans Set-Cookie, car la session n'a pas changé) et la
 * variante HTML d'une même URL ne soient jamais confondues par un cache — mais
 * ce header n'est pas fiable sur tous les caches intermédiaires : constaté en
 * absent sur les réponses réelles de production derrière hCDN (CDN Hostinger).
 * Un cache qui ignore Vary peut mémoriser la réponse JSON d'une navigation
 * Inertia (SPA) et la resservir telle quelle à la navigation directe suivante
 * sur la même URL — potentiellement à un autre utilisateur/une autre session.
 *
 * Défense en profondeur : on ne fait plus confiance à un cache tiers pour
 * respecter Vary — chaque réponse dynamique porte elle-même une interdiction
 * de cache explicite et standard (Cache-Control), qu'un cache HTTP conforme
 * doit respecter indépendamment de Vary. Ne s'applique jamais à une réponse
 * qui déclare déjà sa propre politique de cache (ex: ClientDashboardController,
 * ParametreController) — simple filet de sécurité par défaut, jamais une
 * substitution à un choix explicite du contrôleur.
 */
class PreventCachingOfDynamicResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
