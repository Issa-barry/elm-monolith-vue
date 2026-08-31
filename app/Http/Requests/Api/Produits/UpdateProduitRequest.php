<?php

namespace App\Http\Requests\Api\Produits;

use App\Enums\ProduitStatut;
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
        $orgId = $this->user()->organization_id;
        // Variante par défaut du produit édité — exclue de la contrainte d'unicité ci-dessous
        // (sinon une mise à jour qui ne touche pas au code-barres se rejetterait elle-même).
        $varianteId = $this->route('produit')->variantePrincipale()->first()?->id;

        return [
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'categorie_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'fournisseur_id' => [
                'nullable',
                Rule::exists('fournisseurs', 'id')->where('organization_id', $orgId),
            ],
            'code_barres' => [
                'nullable', 'string', 'max:100',
                Rule::unique('produit_variantes', 'code_barres')
                    ->where('organization_id', $orgId)
                    ->ignore($varianteId),
            ],
            'produit_type_id' => [
                'sometimes', 'required',
                Rule::exists('produit_types', 'id')->where('organization_id', $orgId)->where('statut', 'actif'),
            ],
            'statut' => ['nullable', Rule::in(ProduitStatut::values())],
            'prix_usine' => ['nullable', 'integer', 'min:0'],
            'prix_usine_tricycle' => ['nullable', 'integer', 'min:0'],
            // Tarifs par nature de client — n'ont de sens que pour un produit fabricable ;
            // ProduitService::raisonIncoherencePrix() les rend obligatoires pour ce type
            // précisément (source de vérité unique, partagée avec le formulaire web).
            'prix_externe' => ['nullable', 'integer', 'min:0'],
            'prix_revendeur' => ['nullable', 'integer', 'min:0'],
            'prix_distributeur' => ['nullable', 'integer', 'min:0'],
            'prix_vente' => ['nullable', 'integer', 'min:0'],
            'prix_achat' => ['nullable', 'integer', 'min:0'],
            'cout' => ['nullable', 'integer', 'min:0'],
            'alerte_stock_active' => ['boolean'],
            // Le seuil se règle désormais PAR SITE (cf. ProduitSeuilAlerteService), uniquement
            // depuis le formulaire web (fiche produit > Modifier) : non exposé ici.
            'description' => ['nullable', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:2048'],
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
            'produit_type_id.required' => 'Le type de produit est obligatoire.',
            'produit_type_id.exists' => 'Le type sélectionné est invalide ou inactif.',
            'statut.in' => 'Le statut sélectionné est invalide.',
            'prix_usine.integer' => 'Le prix usine doit être un nombre entier.',
            'prix_usine.min' => 'Le prix usine ne peut pas être négatif.',
            'prix_usine_tricycle.integer' => 'Le prix usine tricycle doit être un nombre entier.',
            'prix_usine_tricycle.min' => 'Le prix usine tricycle ne peut pas être négatif.',
            'prix_vente.integer' => 'Le prix de vente doit être un nombre entier.',
            'prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
            'prix_achat.integer' => 'Le prix d\'achat doit être un nombre entier.',
            'prix_achat.min' => 'Le prix d\'achat ne peut pas être négatif.',
            'cout.integer' => 'Le coût doit être un nombre entier.',
            'cout.min' => 'Le coût ne peut pas être négatif.',
            'alerte_stock_active.required' => 'Indiquez si vous souhaitez être alerté en cas de stock faible.',
            'alerte_stock_active.boolean' => 'Le champ alerte doit être vrai ou faux.',
            'images.*.image' => 'Le fichier doit être une image.',
            'images.*.max' => 'Chaque image ne peut pas dépasser 2 Mo.',
        ];
    }
}
