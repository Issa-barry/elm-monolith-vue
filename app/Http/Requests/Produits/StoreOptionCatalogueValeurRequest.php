<?php

namespace App\Http\Requests\Produits;

use Illuminate\Foundation\Http\FormRequest;

class StoreOptionCatalogueValeurRequest extends FormRequest
{
    public function authorize(): bool
    {
        $option = $this->route('option');

        return $this->user()->can('options.update')
            && $this->user()->organization_id === $option->organization_id;
    }

    public function rules(): array
    {
        return [
            'valeur' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'valeur.required' => 'La valeur est obligatoire.',
            'valeur.max' => 'La valeur ne peut pas dépasser 255 caractères.',
        ];
    }
}
