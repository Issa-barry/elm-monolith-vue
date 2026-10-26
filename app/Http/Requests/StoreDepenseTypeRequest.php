<?php

namespace App\Http\Requests;

use App\Enums\CategorieDepense;
use App\Enums\TypeVariablePaie;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'categorie' => ['required', Rule::in(CategorieDepense::values())],
            'commentaire_obligatoire' => ['boolean'],
            'justificatif_obligatoire' => ['boolean'],
            // ADR 0031 : seule une dépense véhicule a un véhicule pour retrouver sa commande.
            'commande_obligatoire' => ['boolean', Rule::prohibitedIf(fn () => $this->boolean('commande_obligatoire') && $this->input('categorie') !== CategorieDepense::VEHICULE->value)],
            // Effet sur la paie : retenues seulement, et pour un type « Employé » uniquement. Une prime
            // vient du référentiel RH, jamais d'une dépense (docs/paie.md).
            'type_paie' => [
                'nullable', 'string', Rule::in(TypeVariablePaie::valeursRetenues()),
                Rule::prohibitedIf(fn () => filled($this->input('type_paie')) && $this->input('categorie') !== CategorieDepense::EMPLOYE->value),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'libelle.required' => 'Le libellé est obligatoire.',
            'categorie.required' => 'Le concerné est obligatoire.',
            'categorie.in' => 'Le concerné sélectionné est invalide.',
            'commande_obligatoire.prohibited' => 'Seul un type de dépense véhicule peut exiger une commande.',
            'type_paie.in' => 'Une dépense ne peut que retenir sur le salaire (avance, retenue, absence, autre déduction) : les primes se gèrent dans RH.',
            'type_paie.prohibited' => 'Seul un type de dépense « Employé » peut avoir un effet sur la paie.',
        ];
    }
}
