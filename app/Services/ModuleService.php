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
}
