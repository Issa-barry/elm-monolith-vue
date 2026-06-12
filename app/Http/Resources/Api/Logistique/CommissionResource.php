<?php

namespace App\Http\Resources\Api\Logistique;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'base_calcul'        => $this->base_calcul?->value,
            'valeur_base'        => (float) $this->valeur_base,
            'quantite_reference' => $this->quantite_reference,
            'montant_total'      => (float) $this->montant_total,
            'montant_verse'      => (float) $this->montant_verse,
            'montant_restant'    => $this->montant_restant,
            'statut'             => $this->statut?->value,
            'statut_label'       => $this->statut_label,
            'parts'              => CommissionPartResource::collection($this->whenLoaded('parts')),
        ];
    }
}
