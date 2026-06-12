<?php

namespace App\Http\Requests\Api\Logistique;

use Illuminate\Foundation\Http\FormRequest;

class SaisirQuantitesChargeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'string'],
            'lignes.*.quantite_chargee' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'lignes.required' => 'La liste des lignes est requise.',
            'lignes.*.id.required' => 'L\'identifiant de chaque ligne est requis.',
            'lignes.*.quantite_chargee.required' => 'La quantité chargée est requise pour chaque ligne.',
            'lignes.*.quantite_chargee.integer' => 'La quantité chargée doit être un entier.',
            'lignes.*.quantite_chargee.min' => 'La quantité chargée doit être supérieure ou égale à 0.',
        ];
    }
}
