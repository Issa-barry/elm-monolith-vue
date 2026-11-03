<?php

namespace App\Http\Resources\Api\Parrain;

use App\Enums\ModePaiement;
use App\Models\PaiementFichePaiement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versement reçu par un parrain. Volontairement sans compte de trésorerie, sans note interne et sans
 * auteur du paiement.
 *
 * @mixin PaiementFichePaiement
 */
class PaiementParrainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date_paiement?->toDateString(),
            'montant' => (float) $this->montant,
            'mode_paiement' => $this->mode_paiement,
            'mode_paiement_label' => ModePaiement::tryFrom((string) $this->mode_paiement)?->label() ?? $this->mode_paiement,
            'reference_paiement' => $this->reference_paiement,
            'fiche_reference' => $this->fiche?->reference,
            'periode_reference' => $this->fiche?->periode?->reference,
        ];
    }
}
