<?php

namespace App\Http\Requests\Api\Produits;

use Illuminate\Foundation\Http\FormRequest;

class AjusterStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'augmenter' => ['nullable', 'integer', 'min:1'],
            'diminuer' => ['nullable', 'integer', 'min:1'],
            'motif' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'augmenter.integer' => 'La quantité doit être un nombre entier.',
            'augmenter.min' => 'La quantité doit être supérieure à 0.',
            'diminuer.integer' => 'La quantité doit être un nombre entier.',
            'diminuer.min' => 'La quantité doit être supérieure à 0.',
            'motif.max' => 'Le motif ne peut pas dépasser 500 caractères.',
        ];
    }
}
