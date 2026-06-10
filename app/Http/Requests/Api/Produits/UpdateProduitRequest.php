<?php

namespace App\Http\Requests\Api\Produits;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $produit = $this->route('produit');

        return $this->user()->can('produits.update')
            && $this->user()->organization_id === $produit->organization_id;
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'code_fournisseur' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(ProduitType::values())],
            'statut' => ['nullable', Rule::in(ProduitStatut::values())],
            'prix_usine' => ['nullable', 'integer', 'min:0'],
            'prix_vente' => ['nullable', 'integer', 'min:0'],
            'prix_achat' => ['nullable', 'integer', 'min:0'],
            'cout' => ['nullable', 'integer', 'min:0'],
            'qte_stock' => ['nullable', 'integer', 'min:0'],
            'seuil_alerte_stock' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_alerte' => ['boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom du produit est obligatoire.',
            'nom.string' => 'Le nom doit être une chaîne de caractères.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'code_fournisseur.max' => 'Le code fournisseur ne peut pas dépasser 100 caractères.',
            'type.in' => 'Le type sélectionné est invalide.',
            'statut.in' => 'Le statut sélectionné est invalide.',
            'prix_usine.integer' => 'Le prix usine doit être un nombre entier.',
            'prix_usine.min' => 'Le prix usine ne peut pas être négatif.',
            'prix_vente.integer' => 'Le prix de vente doit être un nombre entier.',
            'prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
            'prix_achat.integer' => 'Le prix d\'achat doit être un nombre entier.',
            'prix_achat.min' => 'Le prix d\'achat ne peut pas être négatif.',
            'cout.integer' => 'Le coût doit être un nombre entier.',
            'cout.min' => 'Le coût ne peut pas être négatif.',
            'qte_stock.integer' => 'La quantité en stock doit être un nombre entier.',
            'qte_stock.min' => 'La quantité en stock ne peut pas être négative.',
            'seuil_alerte_stock.integer' => 'Le seuil d\'alerte doit être un nombre entier.',
            'seuil_alerte_stock.min' => 'Le seuil d\'alerte ne peut pas être négatif.',
            'is_alerte.boolean' => 'Le champ alerte doit être vrai ou faux.',
            'image.image' => 'Le fichier doit être une image.',
            'image.max' => 'L\'image ne peut pas dépasser 2 Mo.',
        ];
    }
}
