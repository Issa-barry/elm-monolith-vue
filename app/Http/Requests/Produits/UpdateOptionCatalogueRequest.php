<?php

namespace App\Http\Requests\Produits;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOptionCatalogueRequest extends FormRequest
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
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => "Le nom de l'option est obligatoire.",
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
        ];
    }
}
