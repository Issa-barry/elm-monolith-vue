<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Référence véhicule propre à `Api\Client\LivraisonsEnCoursController`
 * — volontairement SANS `id` (contrat existant conservé tel quel, distinct de
 * `VehiculeSummary` qui en porte un). Ne pas fusionner ces deux DTOs sans
 * vérifier d'abord que `elm-vitrine-nuxt`/le mobile ne dépendent pas de
 * l'absence actuelle de `vehicule.id` ici.
 */
final class LivraisonEnCoursVehicule implements JsonSerializable
{
    public function __construct(
        public readonly string $nom,
        public readonly string $immatriculation,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'nom' => $this->nom,
            'immatriculation' => $this->immatriculation,
        ];
    }
}
