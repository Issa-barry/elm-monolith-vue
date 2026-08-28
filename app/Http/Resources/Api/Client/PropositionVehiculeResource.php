<?php

namespace App\Http\Resources\Api\Client;

use App\Models\PropositionVehicule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PropositionVehicule
 */
class PropositionVehiculeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom_vehicule' => $this->nom_vehicule,
            'marque' => $this->marque,
            'modele' => $this->modele,
            'immatriculation' => $this->immatriculation,
            'type_vehicule' => $this->type_vehicule,
            'commentaire' => $this->commentaire,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut_label,
            'decision_note' => $this->decision_note,
            'photo_url' => $this->photo_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
