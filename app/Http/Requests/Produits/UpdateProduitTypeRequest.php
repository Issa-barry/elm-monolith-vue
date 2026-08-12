<?php

namespace App\Http\Requests\Produits;

use App\Enums\ProduitTypeStatut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProduitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->route('type');

        return $this->user()->can('type-produits.update')
            && $this->user()->organization_id === $type->organization_id;
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'statut' => ['nullable', Rule::in(ProduitTypeStatut::values())],
            'gere_stock' => ['sometimes', 'required', 'boolean'],
            'vendable' => ['sometimes', 'required', 'boolean'],
            'achetable' => ['sometimes', 'required', 'boolean'],
            'prix_achat_requis' => ['sometimes', 'required', 'boolean'],
            'prix_usine_requis' => ['sometimes', 'required', 'boolean'],
            'prix_vente_requis' => ['sometimes', 'required', 'boolean'],
            'champ_prix_reference' => ['nullable', Rule::in(['prix_achat', 'prix_usine'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $champ = $this->input('champ_prix_reference');
            if ($champ === null || ! $this->has('champ_prix_reference')) {
                return;
            }
            $type = $this->route('type');
            $requisKey = $champ === 'prix_achat' ? 'prix_achat_requis' : 'prix_usine_requis';
            $requis = $this->has($requisKey) ? $this->boolean($requisKey) : (bool) $type->{$requisKey};
            if (! $requis) {
                $v->errors()->add('champ_prix_reference', 'Le champ de référence pour la marge doit faire partie des prix obligatoires de ce type.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom du type est obligatoire.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'statut.in' => 'Le statut sélectionné est invalide.',
            'champ_prix_reference.in' => 'Le champ de référence doit être le prix d\'achat ou le prix usine.',
        ];
    }
}
