<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Gains d'un compte pour une de ses activités (ADR 0054), mêmes champs que `summary` : la somme des
 * lignes de `par_activite` donne `summary`, chaque part n'étant comptée qu'une fois, dans l'activité
 * de son bénéficiaire.
 */
final class ActiviteEarningsRow implements JsonSerializable
{
    public function __construct(
        public readonly string $activite,
        public readonly string $libelle,
        public readonly float $totalEarned,
        public readonly float $totalPaid,
        public readonly float $enAttenteValidation,
        public readonly float $fraisDepensesTotal,
        public readonly float $balance,
        public readonly int $operationsCount,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'activite' => $this->activite,
            'libelle' => $this->libelle,
            'total_earned' => $this->totalEarned,
            'total_paid' => $this->totalPaid,
            'en_attente_validation' => $this->enAttenteValidation,
            'frais_depenses_total' => $this->fraisDepensesTotal,
            'balance' => $this->balance,
            'operations_count' => $this->operationsCount,
        ];
    }
}
