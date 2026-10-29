<?php

namespace App\Http\Requests;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Models\TypeRemuneration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création et modification d'un type de prime ou d'avantage. La permission de base est contrôlée
 * ici, avant la validation des champs ; le droit de validation et l'organisation le sont par
 * TypeRemunerationService.
 */
class TypeRemunerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->route('typeRemuneration');

        return $type instanceof TypeRemuneration
            ? $this->user()->can('update', $type)
            : $this->user()->can('create', TypeRemuneration::class);
    }

    public function rules(): array
    {
        $pourcentage = $this->input('mode_calcul') === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE->value;

        return [
            'libelle' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:1000'],
            'categorie' => ['required', Rule::in(CategorieTypeRemuneration::values())],
            'mode_calcul' => ['required', Rule::in(ModeCalculRemuneration::values())],
            'periodicite' => ['required', Rule::in(PeriodiciteRemuneration::values())],
            'nature_montant' => ['required', Rule::in(NatureMontantRemuneration::values())],
            'proratisable' => ['boolean'],
            'cumul_autorise' => ['boolean'],
            'montant_defaut' => $pourcentage ? ['nullable'] : ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'taux_defaut' => $pourcentage ? ['nullable', 'numeric', 'gt:0', 'max:100'] : ['nullable'],
            'date_debut_application' => ['nullable', 'date_format:Y-m-d'],
            'date_fin_application' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_debut_application'],
            'valider' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'libelle.required' => 'Le libellé est obligatoire.',
            'libelle.max' => 'Le libellé ne doit pas dépasser 100 caractères.',
            'code.max' => 'Le code ne doit pas dépasser 30 caractères.',
            'categorie.required' => 'La catégorie est obligatoire.',
            'categorie.in' => 'Catégorie inconnue.',
            'mode_calcul.required' => 'Le mode de calcul est obligatoire.',
            'mode_calcul.in' => 'Mode de calcul inconnu.',
            'periodicite.required' => 'La périodicité est obligatoire.',
            'periodicite.in' => 'Périodicité inconnue.',
            'nature_montant.required' => 'La nature du montant est obligatoire.',
            'nature_montant.in' => 'Nature du montant inconnue.',
            'montant_defaut.numeric' => 'Le montant par défaut doit être un nombre.',
            'montant_defaut.gt' => 'Le montant par défaut doit être supérieur à 0.',
            'taux_defaut.numeric' => 'Le taux par défaut doit être un nombre.',
            'taux_defaut.gt' => 'Le taux par défaut doit être supérieur à 0.',
            'taux_defaut.max' => 'Le taux par défaut ne peut pas dépasser 100 %.',
            'date_fin_application.after_or_equal' => 'La fin d\'application doit être postérieure ou égale au début.',
        ];
    }
}
