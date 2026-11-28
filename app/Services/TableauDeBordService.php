<?php

namespace App\Services;

use App\Features\TableauDeBordFeature;
use App\Models\Organization;
use Laravel\Pennant\Feature;

/** Lecture et modification de l'activation des tableaux de bord d'une organisation (ADR 0071). */
class TableauDeBordService
{
    public static function isActive(string $tableau, Organization $org): bool
    {
        return Feature::for($org)->active($tableau);
    }

    /** @return array<string, bool> clé complète (`tableau_de_bord.ventes`) => actif */
    public static function allForOrg(Organization $org): array
    {
        Feature::for($org)->loadMissing(TableauDeBordFeature::ALL);

        $etats = [];
        foreach (TableauDeBordFeature::ALL as $tableau) {
            $etats[$tableau] = self::isActive($tableau, $org);
        }

        return $etats;
    }

    public static function definir(string $tableau, Organization $org, bool $actif): void
    {
        $actif
            ? Feature::for($org)->activate($tableau)
            : Feature::for($org)->deactivate($tableau);
    }
}
