<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Documente un 403 possible sur toute route gardée par le middleware Spatie
 * `role:...` (ex: `role:client|proprietaire|livreur`) — Scramble détecte
 * nativement `can:`/`Authorize::class` (Laravel natif) mais pas les
 * middlewares tiers comme celui de Spatie (cf. audit OpenAPI du 27/08/2026,
 * point 3). Réutilise `TypeTransformer::toResponse()` sur le MÊME type
 * `AuthorizationException` que le mécanisme natif, pour partager le même
 * composant `#/components/responses/AuthorizationException` plutôt que
 * d'en fabriquer un doublon.
 *
 * Enregistré une seule fois dans `OpenApiServiceProvider` — s'applique
 * automatiquement à tout nouvel endpoint gardé par `role:`, sans qu'il faille
 * dupliquer une annotation de réponse dans chaque contrôleur.
 */
class SpatieRoleAuthorizationExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        $hasRoleMiddleware = collect($routeInfo->route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'role:'));

        if (! $hasRoleMiddleware) {
            return;
        }

        $response = $this->openApiTransformer->toResponse(new ObjectType(AuthorizationException::class));

        if ($response) {
            $operation->addResponse($response);
        }
    }
}
