<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variante = $this->relationLoaded('variantes')
            ? $this->variantes->firstWhere('is_default', true) ?? $this->variantes->first()
            : $this->variantePrincipale()->first();

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'categorie_id' => $this->categorie_id,
            'categorie_nom' => $this->whenLoaded('categorie', fn () => $this->categorie?->nom),
            'sku' => $variante?->sku,
            'code_barres' => $variante?->code_barres,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'type_has_stock' => $this->type?->hasStock() ?? true,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'prix_usine' => $variante?->prix_usine,
            'prix_vente' => $variante?->prix_vente,
            'prix_achat' => $variante?->prix_achat,
            'cout' => $variante?->cout,
            'qte_stock' => $this->qte_stock,
            'seuil_alerte_stock' => $variante?->seuil_alerte_stock,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'is_alerte' => $this->is_alerte,
            'in_stock' => $this->in_stock,
            'is_low_stock' => $this->is_low_stock,
            'is_used' => $this->is_used,
            'has_variantes' => $this->relationLoaded('variantes') ? $this->variantes->count() > 1 : $this->variantes()->count() > 1,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
