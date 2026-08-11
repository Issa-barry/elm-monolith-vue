<?php

namespace App\Http\Requests\Produits;

use App\Enums\CategorieStatut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategorieRequest extends FormRequest
{
    public function authorize(): bool
    {
        $categorie = $this->route('categorie');

        return $this->user()->can('categories.update')
            && $this->user()->organization_id === $categorie->organization_id;
    }

    public function rules(): array
    {
        $orgId = $this->user()->organization_id;
        $categorie = $this->route('categorie');

        // Interdit de choisir soi-même ou un de ses descendants comme parent (cycle).
        $interdits = array_merge([$categorie->id], $categorie->descendantIds());

        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(CategorieStatut::values())],
            'parent_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('organization_id', $orgId),
                Rule::notIn($interdits),
            ],
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
            'parent_id.not_in' => 'Une catégorie ne peut pas être son propre parent ni un de ses descendants.',
        ];
    }
}
