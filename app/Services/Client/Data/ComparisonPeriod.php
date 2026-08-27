<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Bornes de la période précédente utilisée pour `summary_evolution` — cf.
 * `ClientEarningsService::previousPeriodBounds()`. Exposée telle quelle pour
 * qu'un frontend puisse afficher, par exemple, "vs 01/07 - 31/07" sans avoir
 * à recalculer ces dates lui-même.
 */
final class ComparisonPeriod implements JsonSerializable
{
    public function __construct(
        public readonly string $dateDebut,
        public readonly string $dateFin,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'date_debut' => $this->dateDebut,
            'date_fin' => $this->dateFin,
        ];
    }
}
