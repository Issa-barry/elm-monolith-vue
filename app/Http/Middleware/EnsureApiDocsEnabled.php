<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupe-circuit HTTP explicite pour /docs/api et /docs/vitrine (UI + JSON),
 * indépendant de APP_ENV — préprod et prod partagent APP_ENV=production par
 * choix délibéré du projet (cf. .env.preprod.example, seul SENTRY_ENVIRONMENT
 * distingue les deux), donc l'environnement seul ne permet pas de fermer la
 * doc sur l'un sans la fermer aussi sur l'autre. Fermé par défaut
 * (`config('scramble.docs_enabled')` retombe sur `false` si
 * `API_DOCS_ENABLED` n'est pas défini) : chaque serveur doit activer la doc
 * explicitement, jamais l'inverse. `API_DOCS_ENABLED=false` masque totalement
 * la documentation (y compris en local) sans toucher à `composer
 * openapi:export` ni à la CI (`scramble:export`/`scramble:analyze` sont des
 * commandes console, jamais routées par ce middleware HTTP).
 *
 * Placé AVANT `RestrictedDocsAccess` dans `config('scramble.middleware')` :
 * quand la doc est désactivée, on répond 404 (elle "n'existe pas") plutôt que
 * le 403 ("elle existe mais vous n'avez pas le droit") que renvoie le Gate
 * `viewApiDocs` pour un utilisateur non autorisé. Quand le flag est à `true`,
 * ce middleware laisse simplement passer la requête — le Gate `viewApiDocs`
 * / le comportement natif de `RestrictedDocsAccess` s'appliquent ensuite
 * normalement, inchangés (seconde barrière, jamais court-circuitée par ce
 * flag).
 */
class EnsureApiDocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('scramble.docs_enabled'), 404);

        return $next($request);
    }
}
