<?php

namespace App\Http\Resources\Api\Logistique;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransfertLigneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Les listes chargent les lignes sans leur variante : whenLoaded() renverrait alors un
        // MissingValue, sur lequel relationLoaded() plante (500 dès qu'un transfert a une ligne).
        $variante = $this->resource->relationLoaded('variante') ? $this->resource->variante : null;
        $produit = $variante?->relationLoaded('produit') ? $variante->produit : null;

        return [
            'id' => $this->id,
            'variante_id' => $this->variante_id,
            'produit_nom' => $produit?->nom,
            'variante_libelle' => $variante?->libelle,
            'sku' => $variante?->sku,
            'produit_image_url' => $produit && $produit->image_url ? url($produit->image_url) : null,
            'quantite_demandee' => $this->quantite_demandee,
            'quantite_chargee' => $this->quantite_chargee,
            'quantite_recue' => $this->quantite_recue,
            'quantite_retournee' => (int) $this->quantite_retournee,
            'ecart_type' => $this->ecart_type?->value,
            'ecart_label' => $this->ecart_label,
            'ecart' => $this->ecart,
            'ecart_motif' => $this->ecart_motif,
            'reception_complete' => $this->estReceptionComplete(),
        ];
    }
}
