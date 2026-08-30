<?php

namespace App\Http\Requests\Produits;

use App\Enums\ProduitTypeStatut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProduitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('type-produits.create');
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'statut' => ['nullable', Rule::in(ProduitTypeStatut::values())],
            'gere_stock' => ['required', 'boolean'],
            'vendable' => ['required', 'boolean'],
            'achetable' => ['required', 'boolean'],
            'prix_achat_requis' => ['required', 'boolean'],
            'prix_usine_requis' => ['required', 'boolean'],
            'prix_vente_requis' => ['required', 'boolean'],
            'champ_prix_reference' => ['nullable', Rule::in(['prix_achat', 'prix_usine'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Le champ de référence pour le contrôle de marge doit obligatoirement faire partie des
     * prix requis de ce type, ET le prix de vente lui-même doit être requis — sinon la règle
     * "prix_vente > champ_prix_reference" appliquée par ProduitService compare un prix de vente
     * jamais saisi (toujours 0) à un champ renseigné, rejetant systématiquement toute création
     * (bug réel constaté sur le type par défaut « Matière de production », non vendable, corrigé
     * le 30/08/2026).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $champ = $this->input('champ_prix_reference');
            if ($champ === null) {
                return;
            }
            $requisKey = $champ === 'prix_achat' ? 'prix_achat_requis' : 'prix_usine_requis';
            if (! $this->boolean($requisKey)) {
                $v->errors()->add('champ_prix_reference', 'Le champ de référence pour la marge doit faire partie des prix obligatoires de ce type.');
            }
            if (! $this->boolean('prix_vente_requis')) {
                $v->errors()->add('champ_prix_reference', 'Le prix de vente doit être obligatoire pour ce type si un champ de référence de marge est défini.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom du type est obligatoire.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'statut.in' => 'Le statut sélectionné est invalide.',
            'gere_stock.required' => 'Indiquez si ce type de produit gère du stock.',
            'vendable.required' => 'Indiquez si ce type de produit est vendable.',
            'achetable.required' => 'Indiquez si ce type de produit est achetable.',
            'prix_achat_requis.required' => 'Indiquez si le prix d\'achat est requis pour ce type.',
            'prix_usine_requis.required' => 'Indiquez si le prix usine est requis pour ce type.',
            'prix_vente_requis.required' => 'Indiquez si le prix de vente est requis pour ce type.',
            'champ_prix_reference.in' => 'Le champ de référence doit être le prix d\'achat ou le prix usine.',
        ];
    }
}
