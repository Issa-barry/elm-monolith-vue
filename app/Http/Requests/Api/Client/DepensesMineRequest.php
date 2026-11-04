<?php

namespace App\Http\Requests\Api\Client;

use App\Enums\StatutDepense;
use App\Support\Periodes\Periode;
use App\Support\Periodes\PeriodeComptableFiltre;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de `GET /v1/mobile/depenses/mine` — liste consolidée (tous véhicules
 * accessibles + dépenses rattachées directement au livreur) contrairement à
 * `GET /v1/mobile/vehicules/{id}/frais` (un seul véhicule). Pas de raccourci "period" ici (contrairement au dashboard) :
 * cet endpoint est une liste paginée, pas un calcul de solde, une période par
 * défaut implicite ferait disparaître des dépenses sans que l'appelant l'ait
 * demandé. Trois façons de borner `date_depense`, qui s'excluent : la période comptable
 * (annee/mois/quinzaine), une période rapide (`periode`, mêmes clés que l'accueil, résolue par
 * Periode, `tout` = sans borne) ou date_debut/date_fin.
 */
class DepensesMineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vehicule_id' => ['nullable', 'string'],
            'depense_type_id' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(StatutDepense::values())],
            'periode' => ['nullable', Rule::in(DashboardMineRequest::PERIODES), 'prohibits:date_debut,date_fin'],
            ...PeriodeComptableFiltre::regles(['periode', 'date_debut', 'date_fin']),
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);
        $cle = $this->input('periode');
        $periodeRapide = $cle !== null ? Periode::rapide($cle, CarbonImmutable::now()) : null;

        [$debut, $fin] = match (true) {
            $periodeComptable !== null => [$periodeComptable->debut->toDateString(), $periodeComptable->fin->toDateString()],
            $cle !== null => [$periodeRapide?->debut->toDateString(), $periodeRapide?->fin->toDateString()],
            default => [$this->input('date_debut'), $this->input('date_fin')],
        };

        return [
            'vehicule_id' => $this->input('vehicule_id'),
            'depense_type_id' => $this->input('depense_type_id'),
            'statut' => $this->input('statut'),
            'periode' => $cle,
            'annee' => $periodeComptable?->annee,
            'mois' => $periodeComptable?->mois,
            'quinzaine' => $periodeComptable?->quinzaine,
            'date_debut' => $debut,
            'date_fin' => $fin,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
