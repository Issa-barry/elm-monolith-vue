<?php

namespace App\Http\Resources\Api\Client;

use App\Models\Depense;
use App\Models\DepenseType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Depense
 */
class DepenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicule = $this->vehiculeBeneficiaire;

        return [
            'id' => $this->id,
            'date' => $this->date_depense?->toDateString(),
            'montant' => (float) $this->montant,
            'type_code' => DepenseType::normalizedCode($this->depenseType?->code, $this->depenseType?->libelle),
            'type_label' => $this->depenseType?->libelle ?? 'Autre',
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'commentaire' => $this->commentaire,
            'vehicule' => $vehicule ? [
                'id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
            ] : null,
        ];
    }
}
