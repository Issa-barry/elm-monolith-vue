<?php

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Projection commune d'un Site pour l'affichage — extrait de SiteController::siteData(),
 * partagé entre l'index, la fiche détail et le formulaire d'édition pour qu'ils restent
 * garantis d'afficher exactement les mêmes données.
 */
final class SiteDataFormatter
{
    public static function pour(Site $s): array
    {
        return [
            'id' => $s->id,
            'nom' => $s->nom,
            'code' => $s->code,
            'type' => $s->type?->value,
            'type_label' => $s->type_label,
            'statut' => $s->statut?->value,
            'statut_label' => $s->statut_label,
            'localisation' => $s->localisation,
            'pays' => $s->pays,
            'ville' => $s->ville,
            'quartier' => $s->quartier,
            'description' => $s->description,
            'parent_id' => $s->parent_id,
            'latitude' => $s->latitude,
            'longitude' => $s->longitude,
            'telephone' => $s->telephone,
            'email' => $s->email,
            'parent_nom' => $s->parent?->nom,
            'enfants_count' => (int) ($s->enfants_count ?? $s->enfants()->count()),
        ];
    }
}
