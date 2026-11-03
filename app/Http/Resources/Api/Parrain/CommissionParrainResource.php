<?php

namespace App\Http\Resources\Api\Parrain;

use App\Http\Controllers\Settings\CommissionRegleController;
use App\Models\CommissionEnveloppePart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Part d'un parrain sur une opération. Volontairement sans client, sans montant de la commande et sans
 * part des autres bénéficiaires.
 *
 * @mixin CommissionEnveloppePart
 */
class CommissionParrainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enveloppe = $this->enveloppe;
        $source = $enveloppe?->source;
        $vehicule = $source?->vehicule;
        $processus = $enveloppe?->processus?->code;

        return [
            'id' => $this->id,
            'date' => $enveloppe?->earned_at?->toDateString(),
            'reference' => $source?->reference,
            'processus_label' => $processus ? CommissionRegleController::processusLabel($processus) : null,
            'vehicule' => $vehicule ? [
                'id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
            ] : null,
            'montant_unitaire' => $this->montant_unitaire_snapshot,
            'montant_a_payer' => (float) $this->montant_a_payer,
            'montant_verse' => (float) $this->montant_verse,
            'montant_restant' => (float) $this->montant_restant,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
        ];
    }
}
