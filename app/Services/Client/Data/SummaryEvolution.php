<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Évolution des 5 KPI de `ClientEarningsService::calculateEarnings()`
 * (même clés que `summary`) entre la période sélectionnée et la période
 * précédente de même durée — cf. `ClientEarningsService::summaryEvolution()`.
 *
 * Toujours un champ ADDITIF au contrat existant (`summary_evolution`,
 * jamais une transformation de `summary` lui-même) : les consommateurs
 * actuels de `summary.total_earned` (nombre brut) ne doivent jamais voir ce
 * type changer.
 */
final class SummaryEvolution implements JsonSerializable
{
    public function __construct(
        public readonly KpiEvolution $totalEarned,
        public readonly KpiEvolution $totalPaid,
        public readonly KpiEvolution $fraisDepensesTotal,
        public readonly KpiEvolution $balance,
        public readonly KpiEvolution $operationsCount,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'total_earned' => $this->totalEarned,
            'total_paid' => $this->totalPaid,
            'frais_depenses_total' => $this->fraisDepensesTotal,
            'balance' => $this->balance,
            'operations_count' => $this->operationsCount,
        ];
    }
}
