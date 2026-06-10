<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'code_interne' => $this->code_interne,
            'code_fournisseur' => $this->code_fournisseur,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'type_has_stock' => $this->type?->hasStock() ?? true,
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut?->label(),
            'prix_usine' => $this->prix_usine,
            'prix_vente' => $this->prix_vente,
            'prix_achat' => $this->prix_achat,
            'cout' => $this->cout,
            'qte_stock' => $this->qte_stock,
            'seuil_alerte_stock' => $this->seuil_alerte_stock,
            'description' => $this->description,
            'image_url' => $this->image_url ? url($this->image_url) : null,
            'is_critique' => $this->is_critique,
            'in_stock' => $this->in_stock,
            'is_low_stock' => $this->is_low_stock,
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
