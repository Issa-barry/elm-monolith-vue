<?php

namespace App\Http\Requests\Api\Produits;

use App\Enums\MotifAjustementStock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AjusterStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'augmenter'    => ['nullable', 'integer', 'min:1'],
            'diminuer'     => ['nullable', 'integer', 'min:1'],
            'motif_type'   => ['required', Rule::in(MotifAjustementStock::validValues())],
            'motif_detail' => ['nullable', 'required_if:motif_type,autre', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'augmenter.integer'        => 'La quantité doit être un nombre entier.',
            'augmenter.min'            => 'La quantité doit être supérieure à 0.',
            'diminuer.integer'         => 'La quantité doit être un nombre entier.',
            'diminuer.min'             => 'La quantité doit être supérieure à 0.',
            'motif_type.required'      => 'Le motif est obligatoire.',
            'motif_type.in'            => 'Le motif sélectionné est invalide.',
            'motif_detail.required_if' => 'Veuillez préciser le motif.',
            'motif_detail.max'         => 'Le détail du motif ne peut pas dépasser 500 caractères.',
        ];
    }
}
