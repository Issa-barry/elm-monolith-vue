<?php

namespace App\Http\Requests\Settings;

use App\Models\CommissionCibleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionRegleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        return [
            // Phase 2 : uniquement les deux cibles déjà couvertes par le pont Phase 1.
            // equipe_depot / it seront ajoutées à ce référentiel en Phase 3 (cf.
            // conception cible §0.2.4 — bénéficiaire IT explicitement non tranché).
            'cible_type' => ['required', Rule::in([
                CommissionCibleType::CODE_PROPRIETAIRE,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            ])],
            'scope_type' => ['required', Rule::in(['categorie', 'global'])],
            'categorie_id' => ['required_if:scope_type,categorie', 'nullable', 'string', 'exists:categories,id'],
            'montant' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'cible_type.required' => 'La cible est obligatoire.',
            'categorie_id.required_if' => 'Choisissez une catégorie, ou passez en portée globale.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être positif ou nul.',
        ];
    }
}
