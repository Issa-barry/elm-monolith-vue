<?php

namespace App\Services\Tresorerie;

use App\Exceptions\Tresorerie\SiteCentralTresorerieIndisponibleException;
use App\Models\Site;

/**
 * Résolution du site central de trésorerie d'une organisation (ADR 0017) — le site vers lequel
 * remontent les flux centraux (remises, règlements inter-agences, paiement des fiches sans
 * agence). Rôle explicite porté par Site::is_central_tresorerie, indépendant du type de site :
 * jamais déduit d'un type ni d'un ->first() arbitraire. Unicité par organisation garantie par
 * Site::saving() plutôt qu'un index SQL partiel (portabilité SGBD).
 */
class SiteCentralTresorerieResolver
{
    public function central(string $organizationId): Site
    {
        return $this->centralOuNull($organizationId)
            ?? throw SiteCentralTresorerieIndisponibleException::pourOrganisation($organizationId);
    }

    public function centralOuNull(string $organizationId): ?Site
    {
        return Site::where('organization_id', $organizationId)
            ->where('is_central_tresorerie', true)
            ->first();
    }

    /** Désigne $site comme site central de son organisation — l'ancien perd le rôle. */
    public function designer(Site $site): void
    {
        $site->update(['is_central_tresorerie' => true]);
    }
}
