<?php

namespace App\Http\Requests\Produits;

use Illuminate\Foundation\Http\FormRequest;

class StoreOptionCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('options.create');
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
            'valeurs' => ['nullable', 'array'],
            'valeurs.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => "Le nom de l'option est obligatoire.",
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'valeurs.*.required' => 'Une valeur proposée ne peut pas être vide.',
        ];
    }
}
