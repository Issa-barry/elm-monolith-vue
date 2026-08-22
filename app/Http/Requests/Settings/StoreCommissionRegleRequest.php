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
            // Phase 2 : les trois cibles couvertes par le pont Phase 1 + Site (commission
            // attribuée directement au site métier de l'opération, décision produit
            // 2026-08-21) + Consultant (commission versée au prestataire désigné par
            // l'organisation, décision produit 2026-08-22). `it` reste réservée à la Phase 3
            // (cf. conception cible §0.2.4 — bénéficiaire IT explicitement non tranché, distinct
            // du consultant).
            'cible_type' => ['required', Rule::in([
                CommissionCibleType::CODE_PROPRIETAIRE,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                CommissionCibleType::CODE_SITE,
                CommissionCibleType::CODE_CONSULTANT,
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
