<?php

namespace App\Http\Resources\Api\Logistique;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type_beneficiaire' => $this->type_beneficiaire,
            'beneficiaire_nom' => $this->beneficiaire_nom,
            'taux_commission' => (float) $this->taux_commission,
            'montant_brut' => (float) $this->montant_brut,
            'montant_net' => (float) $this->montant_net,
            'montant_a_payer' => $this->montant_a_payer,
            'montant_verse' => (float) $this->montant_verse,
            'montant_restant' => $this->montant_restant,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut_label,
        ];
    }
}
