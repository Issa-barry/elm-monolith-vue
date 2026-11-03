<?php

namespace App\Http\Requests\Api\Client;

use App\Enums\StatutDepense;
use App\Support\Periodes\PeriodeComptableFiltre;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de `GET /v1/mobile/depenses/mine` — liste consolidée (tous véhicules
 * accessibles + dépenses rattachées directement au livreur) contrairement à
 * `GET /v1/mobile/vehicules/{id}/frais` (un seul véhicule). Pas de raccourci "period" ici (contrairement au dashboard) :
 * cet endpoint est une liste paginée, pas un calcul de solde, une période par
 * défaut implicite ferait disparaître des dépenses sans que l'appelant l'ait
 * demandé. La période comptable (annee/mois/quinzaine) exclut date_debut/date_fin
 * et se traduit par ces mêmes bornes, appliquées à `date_depense`.
 */
class DepensesMineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vehicule_id' => ['nullable', 'string'],
            'depense_type_id' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(StatutDepense::values())],
            ...PeriodeComptableFiltre::regles(['date_debut', 'date_fin']),
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);

        return [
            'vehicule_id' => $this->input('vehicule_id'),
            'depense_type_id' => $this->input('depense_type_id'),
            'statut' => $this->input('statut'),
            'annee' => $periodeComptable?->annee,
            'mois' => $periodeComptable?->mois,
            'quinzaine' => $periodeComptable?->quinzaine,
            'date_debut' => $periodeComptable?->debut->toDateString() ?? $this->input('date_debut'),
            'date_fin' => $periodeComptable?->fin->toDateString() ?? $this->input('date_fin'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
