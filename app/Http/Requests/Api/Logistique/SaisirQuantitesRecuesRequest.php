<?php

namespace App\Http\Requests\Api\Logistique;

use App\Enums\TypeEcartLogistique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaisirQuantitesRecuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $ecartValues = array_column(TypeEcartLogistique::cases(), 'value');

        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'string'],
            'lignes.*.quantite_recue' => ['required', 'integer', 'min:0'],
            'lignes.*.ecart_type' => ['required', Rule::in($ecartValues)],
            'lignes.*.ecart_motif' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'lignes.required' => 'La liste des lignes est requise.',
            'lignes.*.id.required' => 'L\'identifiant de chaque ligne est requis.',
            'lignes.*.quantite_recue.required' => 'La quantité reçue est requise pour chaque ligne.',
            'lignes.*.quantite_recue.integer' => 'La quantité reçue doit être un entier.',
            'lignes.*.quantite_recue.min' => 'La quantité reçue ne peut pas être négative.',
            'lignes.*.ecart_type.required' => 'Le type d\'écart est requis pour chaque ligne.',
            'lignes.*.ecart_type.in' => 'Le type d\'écart est invalide.',
        ];
    }
}
