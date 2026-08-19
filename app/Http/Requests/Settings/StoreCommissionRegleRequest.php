<?php

namespace App\Http\Requests\Settings;

use App\Models\CommissionCibleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionRegleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        return [
            // Phase 2 : uniquement les deux cibles déjà couvertes par le pont Phase 1.
            // equipe_depot / it seront ajoutées à ce référentiel en Phase 3 (cf.
            // conception cible §0.2.4 — bénéficiaire IT explicitement non tranché).
            'cible_type' => ['required', Rule::in([
                CommissionCibleType::CODE_PROPRIETAIRE,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            ])],
            'scope_type' => ['required', Rule::in(['categorie', 'global'])],
            'categorie_id' => ['required_if:scope_type,categorie', 'nullable', 'string', 'exists:categories,id'],
            // Entier positif ou nul, sans décimales : le GNF n'a pas de subdivision
            // monétaire dans cette application. 0 est une valeur métier légitime
            // (ex: exclure explicitement le propriétaire d'une catégorie précise
            // plutôt que d'hériter silencieusement du barème global) — jamais
            // ambigu avec une cellule non configurée ("—") côté affichage, puisque
            // c'est l'EXISTENCE de la règle qui distingue les deux, pas sa valeur
            // (cf. Paramètres → Commissions, CommissionRegles/Index.vue::cellLabel()).
            // La regex ferme la porte à un contournement du filtre "integer" de PHP
            // (ex: " 600 ", "600 ") qu'un appel API direct pourrait tenter.
            'montant' => ['required', 'regex:/^\d+$/', 'integer', 'min:0', 'max:99999999'],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'cible_type.required' => 'La cible est obligatoire.',
            'categorie_id.required_if' => 'Choisissez une catégorie, ou passez en portée globale.',
            'montant.required' => 'Saisissez un montant entier, 0 ou plus.',
            'montant.regex' => 'Saisissez un montant entier, 0 ou plus.',
            'montant.integer' => 'Saisissez un montant entier, 0 ou plus.',
            'montant.min' => 'Saisissez un montant entier, 0 ou plus.',
        ];
    }
}
