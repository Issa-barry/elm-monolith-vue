<?php

namespace App\Http\Requests\Produits;

use App\Enums\CategorieStatut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('categories.create');
    }

    public function rules(): array
    {
        $orgId = $this->user()->organization_id;

        return [
            'nom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(CategorieStatut::values())],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom de la catégorie est obligatoire.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'statut.in' => 'Le statut sélectionné est invalide.',
            'parent_id.exists' => 'La catégorie parente sélectionnée est invalide.',
        ];
    }
}
