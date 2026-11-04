<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Identité d'un véhicule lié au compte et nature de ses liens (ADR 0054) : `liens` vide pour un
 * véhicule seulement porteur d'une part du compte (équipe quittée, commission de consultant).
 */
final class VehiculeLieSummary implements JsonSerializable
{
    /**
     * @param  string[]  $liens
     */
    public function __construct(
        public readonly string $id,
        public readonly string $nomVehicule,
        public readonly string $immatriculation,
        public readonly array $liens,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'nom_vehicule' => $this->nomVehicule,
            'immatriculation' => $this->immatriculation,
            'liens' => array_values($this->liens),
        ];
    }
}
