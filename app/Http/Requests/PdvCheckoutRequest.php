<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PdvCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:Vente rapide,Client,Livreur'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'vehicule_id' => ['nullable', 'exists:vehicules,id'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'exists:produits,id'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.required' => 'Le mode de vente est obligatoire.',
            'mode.in' => 'Le mode de vente est invalide.',
            'lignes.required' => 'Le panier ne peut pas être vide.',
            'lignes.min' => 'Le panier ne peut pas être vide.',
            'lignes.*.produit_id.required' => 'Le produit est obligatoire pour chaque ligne.',
            'lignes.*.produit_id.exists' => 'Le produit sélectionné est introuvable.',
            'lignes.*.quantite.required' => 'La quantité est obligatoire pour chaque ligne.',
            'lignes.*.quantite.integer' => 'La quantité doit être un entier.',
            'lignes.*.quantite.min' => 'La quantité doit être au moins 1.',
        ];
    }
}
