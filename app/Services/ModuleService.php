<?php

namespace App\Services;

use App\Features\ModuleFeature;
use App\Models\Organization;
use Laravel\Pennant\Feature;

class ModuleService
{
    /**
     * Vérifie si un module est actif pour une organisation.
     */
    public static function isActive(string $module, Organization $org): bool
    {
        return Feature::for($org)->active($module);
    }

    /**
     * Retourne l'état de tous les modules pour une organisation.
     * Clé = nom du feature, valeur = bool.
     */
    public static function allForOrg(Organization $org): array
    {
        $result = [];
        foreach (ModuleFeature::ALL as $module) {
            $result[$module] = self::isActive($module, $org);
        }

        return $result;
    }

    /**
     * Retourne l'organisation "publique" utilisee avant authentification.
     * On prend la premiere organisation disponible.
     */
    public static function publicOrganization(): ?Organization
    {
        return Organization::query()
            ->orderBy('id')
            ->first();
    }

    /**
     * Verifie un module pour l'espace public (avant login).
     * En absence d'organisation, on laisse actif pour eviter un blocage total.
     */
    public static function isPublicActive(string $module): bool
    {
        $org = self::publicOrganization();
        if (! $org) {
            return true;
        }

        return self::isActive($module, $org);
    }
}
