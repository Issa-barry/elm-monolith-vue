<?php

namespace App\Http\Requests;

use App\Models\AffectationRemuneration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attribution d'un type à un salarié, ou modification d'une proposition « à valider ». La
 * permission de base est contrôlée ici, avant les champs ; les règles métier (type validé, dates,
 * chevauchements, mois figés, agence) le sont par AffectationRemunerationService.
 */
class AffectationRemunerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $affectation = $this->route('affectation');

        return $affectation instanceof AffectationRemuneration
            ? $this->user()->can('update', $affectation)
            : $this->user()->can('create', AffectationRemuneration::class);
    }

    public function rules(): array
    {
        $creation = ! $this->route('affectation') instanceof AffectationRemuneration;

        return [
            'employe_id' => $creation ? ['required', 'string'] : ['prohibited'],
            'type_remuneration_id' => $creation ? ['required', 'string'] : ['prohibited'],
            'date_debut' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_debut'],
            'montant' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'taux' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'valider' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'employe_id.required' => 'Le salarié est obligatoire.',
            'employe_id.prohibited' => 'Le salarié d\'une affectation ne change pas : annulez-la et attribuez à nouveau.',
            'type_remuneration_id.required' => 'Le type de prime ou d\'avantage est obligatoire.',
            'type_remuneration_id.prohibited' => 'Le type d\'une affectation ne change pas : annulez-la et attribuez à nouveau.',
            'date_debut.required' => 'La date de début d\'effet est obligatoire.',
            'date_debut.date_format' => 'Date de début invalide.',
            'date_fin.date_format' => 'Date de fin invalide.',
            'date_fin.after_or_equal' => 'La fin d\'effet doit être postérieure ou égale au début.',
            'montant.numeric' => 'Le montant doit être un nombre.',
            'montant.gt' => 'Le montant doit être supérieur à 0.',
            'taux.numeric' => 'Le taux doit être un nombre.',
            'taux.gt' => 'Le taux doit être supérieur à 0.',
            'taux.max' => 'Le taux ne peut pas dépasser 100 %.',
        ];
    }
}
