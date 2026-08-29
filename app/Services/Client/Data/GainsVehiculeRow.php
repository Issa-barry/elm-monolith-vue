<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une ligne de `Api\Client\GainsController::$parVehicule` — la requête source
 * est un JOIN brut (`->get()` sur un query builder renvoie des `stdClass`
 * génériques, sans modèle Eloquent à typer) : sans DTO, Scramble ne peut pas
 * inférer autre chose qu'un tableau de chaînes (cf. audit OpenAPI du
 * 27/08/2026). Endpoint historique déconseillé (voir docblock du contrôleur)
 * — ce DTO ne change aucune valeur, seulement le typage du contrat.
 */
final class GainsVehiculeRow implements JsonSerializable
{
    public function __construct(
        public readonly string $vehiculeId,
        public readonly string $nom,
        public readonly string $immatriculation,
        public readonly float $totalBrut,
        public readonly float $totalNet,
        public readonly float $totalAPayer,
        public readonly float $totalVerse,
        public readonly float $totalRestant,
        public readonly int $nbCommandes,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'vehicule_id' => $this->vehiculeId,
            'nom' => $this->nom,
            'immatriculation' => $this->immatriculation,
            'total_brut' => $this->totalBrut,
            'total_net' => $this->totalNet,
            'total_a_payer' => $this->totalAPayer,
            'total_verse' => $this->totalVerse,
            'total_restant' => $this->totalRestant,
            'nb_commandes' => $this->nbCommandes,
        ];
    }
}
