<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Référence légère à un véhicule ({id, nom_vehicule, immatriculation}) —
 * réutilisée partout où seule l'identité du véhicule est nécessaire, jamais
 * ses capacités/photo/etc. Distincte de `VehiculeEarningsRow` (qui porte en
 * plus les montants). Cf. docblock de `VehiculeEarningsRow` pour le
 * raisonnement DTO vs array (audit OpenAPI du 27/08/2026).
 */
final class VehiculeSummary implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $nomVehicule,
        public readonly string $immatriculation,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'nom_vehicule' => $this->nomVehicule,
            'immatriculation' => $this->immatriculation,
        ];
    }
}
