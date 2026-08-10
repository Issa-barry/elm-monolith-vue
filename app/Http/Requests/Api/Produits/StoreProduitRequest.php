<?php

namespace App\Http\Requests\Api\Produits;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('produits.create');
    }

    public function rules(): array
    {
        $orgId = $this->user()->organization_id;

        return [
            'nom' => ['required', 'string', 'max:255'],
            'categorie_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'code_barres' => [
                'nullable', 'string', 'max:100',
                Rule::unique('produit_variantes', 'code_barres')->where('organization_id', $orgId),
            ],
            'type' => ['required', Rule::in(ProduitType::values())],
            'statut' => ['required', Rule::in(ProduitStatut::values())],
            'prix_usine' => ['nullable', 'integer', 'min:0'],
            'prix_vente' => ['nullable', 'integer', 'min:0'],
            'prix_achat' => ['nullable', 'integer', 'min:0'],
            'cout' => ['nullable', 'integer', 'min:0'],
            'seuil_alerte_stock' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_alerte' => ['boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:2048'],
            // Optionnel : déclinaisons (couleur/taille...). Absent/vide = produit simple.
            'options' => ['nullable', 'array'],
            'options.*.nom' => ['required_with:options', 'string', 'max:100'],
            'options.*.valeurs' => ['required_with:options', 'array', 'min:1'],
            'options.*.valeurs.*' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom du produit est obligatoire.',
            'nom.string' => 'Le nom doit être une chaîne de caractères.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'categorie_id.exists' => 'La catégorie sélectionnée est invalide.',
            'code_barres.max' => 'Le code-barres ne peut pas dépasser 100 caractères.',
            'code_barres.unique' => 'Ce code-barres est déjà utilisé par un autre produit.',
            'type.required' => 'Le type de produit est obligatoire.',
            'type.in' => 'Le type sélectionné est invalide.',
            'statut.required' => 'Le statut du produit est obligatoire.',
            'statut.in' => 'Le statut sélectionné est invalide.',
            'prix_usine.integer' => 'Le prix usine doit être un nombre entier.',
            'prix_usine.min' => 'Le prix usine ne peut pas être négatif.',
            'prix_vente.integer' => 'Le prix de vente doit être un nombre entier.',
            'prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
            'prix_achat.integer' => 'Le prix d\'achat doit être un nombre entier.',
            'prix_achat.min' => 'Le prix d\'achat ne peut pas être négatif.',
            'cout.integer' => 'Le coût doit être un nombre entier.',
            'cout.min' => 'Le coût ne peut pas être négatif.',
            'seuil_alerte_stock.integer' => 'Le seuil d\'alerte doit être un nombre entier.',
            'seuil_alerte_stock.min' => 'Le seuil d\'alerte ne peut pas être négatif.',
            'is_alerte.boolean' => 'Le champ alerte doit être vrai ou faux.',
            'images.*.image' => 'Le fichier doit être une image.',
            'images.*.max' => 'Chaque image ne peut pas dépasser 2 Mo.',
        ];
    }
}
