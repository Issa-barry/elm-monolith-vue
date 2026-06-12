<?php

namespace App\Http\Resources\Api\Logistique;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransfertLigneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $produit = $this->whenLoaded('produit');

        return [
            'id'                 => $this->id,
            'produit_id'         => $this->produit_id,
            'produit_nom'        => $produit ? $produit->nom : null,
            'produit_code'       => $produit ? $produit->code_interne : null,
            'produit_image_url'  => $produit && $produit->image_url ? url($produit->image_url) : null,
            'quantite_demandee'  => $this->quantite_demandee,
            'quantite_chargee'   => $this->quantite_chargee,
            'quantite_recue'     => $this->quantite_recue,
            'ecart_type'         => $this->ecart_type?->value,
            'ecart_label'        => $this->ecart_label,
            'ecart'              => $this->ecart,
            'ecart_motif'        => $this->ecart_motif,
            'reception_complete' => $this->estReceptionComplete(),
        ];
    }
}
