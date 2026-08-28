<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une ligne de `Api\Client\VehiculeCommissionsController` — même raison de DTO
 * que `GainsVehiculeRow` : la requête source est un JOIN brut (`stdClass`,
 * pas de modèle Eloquent à typer pour Scramble).
 */
final class VehiculeCommissionRow implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly ?string $date,
        public readonly float $montantNet,
        public readonly float $montantAPayer,
        public readonly float $montantVerse,
        public readonly float $montantRestant,
        public readonly string $statut,
        public readonly string $mois,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'date' => $this->date,
            'montant_net' => $this->montantNet,
            'montant_a_payer' => $this->montantAPayer,
            'montant_verse' => $this->montantVerse,
            'montant_restant' => $this->montantRestant,
            'statut' => $this->statut,
            'mois' => $this->mois,
        ];
    }
}
