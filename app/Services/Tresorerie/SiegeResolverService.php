<?php

namespace App\Services\Tresorerie;

use App\Enums\SiteType;
use App\Exceptions\Tresorerie\SiegePrincipalIndisponibleException;
use App\Models\Site;

/**
 * Résolution déterministe du "siège principal" d'une organisation — jamais un
 * simple ->first() sur les sites de type SIEGE (une organisation peut, en
 * théorie, avoir plusieurs sites de ce type — ex: siège historique conservé
 * après un déménagement). Le principal est marqué explicitement via
 * Site.is_siege_principal (cf. migration 2026_08_22_100000), garanti unique
 * par organisation par unique() applicatif ici (assignerPrincipal()) plutôt
 * qu'un index SQL partiel (portabilité SGBD).
 */
class SiegeResolverService
{
    public function principal(string $organizationId): Site
    {
        $site = Site::where('organization_id', $organizationId)
            ->where('type', SiteType::SIEGE->value)
            ->where('is_siege_principal', true)
            ->first();

        if ($site) {
            return $site;
        }

        throw SiegePrincipalIndisponibleException::pourOrganisation($organizationId);
    }

    public function principalOuNull(string $organizationId): ?Site
    {
        return Site::where('organization_id', $organizationId)
            ->where('type', SiteType::SIEGE->value)
            ->where('is_siege_principal', true)
            ->first();
    }

    /**
     * Marque $site comme siège principal de son organisation, en retirant le
     * flag de tout autre site — jamais deux principaux simultanés.
     */
    public function assignerPrincipal(Site $site): void
    {
        if (! $site->isSiege()) {
            throw new \InvalidArgumentException("Le site {$site->id} n'est pas de type siège.");
        }

        Site::where('organization_id', $site->organization_id)
            ->where('id', '!=', $site->id)
            ->where('is_siege_principal', true)
            ->update(['is_siege_principal' => false]);

        $site->update(['is_siege_principal' => true]);
    }
}
