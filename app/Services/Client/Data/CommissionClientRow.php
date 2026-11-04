<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une part de commission du compte (ADR 0054) : son origine (`activite`, lue sur le type de
 * bénéficiaire enregistré), son opération, son véhicule et son statut. Volontairement sans client,
 * sans montant de l'opération et sans la part des autres bénéficiaires.
 */
final class CommissionClientRow implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $activite,
        public readonly ?string $activiteLabel,
        public readonly ?string $beneficiaireLibelle,
        public readonly string $operation,
        public readonly ?string $operationLabel,
        public readonly ?string $reference,
        public readonly ?VehiculeSummary $vehicule,
        public readonly ?string $date,
        public readonly float $montantAPayer,
        public readonly float $montantVerse,
        public readonly float $montantRestant,
        public readonly ?string $statut,
        public readonly ?string $statutLabel,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'activite' => $this->activite,
            'activite_label' => $this->activiteLabel,
            'beneficiaire_libelle' => $this->beneficiaireLibelle,
            'operation' => $this->operation,
            'operation_label' => $this->operationLabel,
            'reference' => $this->reference,
            'vehicule' => $this->vehicule,
            'date' => $this->date,
            'montant_a_payer' => $this->montantAPayer,
            'montant_verse' => $this->montantVerse,
            'montant_restant' => $this->montantRestant,
            'statut' => $this->statut,
            'statut_label' => $this->statutLabel,
        ];
    }
}
