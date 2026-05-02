<?php

namespace App\Http\Requests\Settings;

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
        $orgId  = $this->user()->organization_id;
        $typeId = $this->route('depense_type')?->id;

        return [
            'code'             => ['required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('depense_types', 'code')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->ignore($typeId),
            ],
            'libelle'          => ['required', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:500'],
            'requires_vehicle' => ['boolean'],
            'requires_comment' => ['boolean'],
            'is_active'        => ['boolean'],
            'sort_order'       => ['integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required'   => 'Le code est obligatoire.',
            'code.alpha_dash' => 'Le code ne peut contenir que des lettres, chiffres, tirets et underscores.',
            'code.unique'     => 'Ce code existe déjà pour votre organisation.',
            'libelle.required'=> 'Le libellé est obligatoire.',
        ];
    }
}
