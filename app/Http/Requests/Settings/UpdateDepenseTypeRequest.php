<?php

namespace App\Http\Requests\Settings;

use App\Enums\CategorieDepense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'categorie' => ['required', Rule::in(CategorieDepense::values())],
            'commentaire_obligatoire' => ['boolean'],
            'justificatif_obligatoire' => ['boolean'],
            'type_paie' => ['nullable', 'string', Rule::in(['prime', 'autre_gain', 'avance', 'retenue', 'absence', 'autre_deduction'])],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'libelle.required' => 'Le libellé est obligatoire.',
            'categorie.required' => 'Le concerné est obligatoire.',
            'categorie.in' => 'Le concerné sélectionné est invalide.',
        ];
    }
}
