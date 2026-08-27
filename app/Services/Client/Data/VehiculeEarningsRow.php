<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une ligne de `ClientEarningsService::earningsByVehicule()` — extrait en DTO
 * le 27/08/2026 (audit OpenAPI) : le tableau associatif construit et muté au
 * fil de la boucle (`$stats[$id]['total_earned'] += ...`) empêchait Scramble
 * d'inférer une forme d'objet pour `par_vehicule` (il retombait sur
 * `string[]`, un contrat OpenAPI objectivement faux par rapport à la réponse
 * réelle). Un DTO typé résout le problème à la source — Scramble lit les
 * types de propriétés déclarés via `jsonSerialize()` — et documente en même
 * temps la forme de cette ligne dans le domaine PHP, pas seulement dans la
 * spec générée. Suit la convention déjà en place (`ClientIdentity`) :
 * propriétés PHP en camelCase, clés JSON en snake_case via `jsonSerialize()`
 * — jamais l'inverse, le contrat API existant ne bouge pas.
 */
final class VehiculeEarningsRow implements JsonSerializable
{
    public function __construct(
        public readonly string $vehiculeId,
        public readonly string $nomVehicule,
        public readonly string $immatriculation,
        public readonly float $fraisDepenses,
        public readonly float $totalEarned,
        public readonly float $totalPaid,
        public readonly float $balance,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'vehicule_id' => $this->vehiculeId,
            'nom_vehicule' => $this->nomVehicule,
            'immatriculation' => $this->immatriculation,
            'frais_depenses' => $this->fraisDepenses,
            'total_earned' => $this->totalEarned,
            'total_paid' => $this->totalPaid,
            'balance' => $this->balance,
        ];
    }
}
