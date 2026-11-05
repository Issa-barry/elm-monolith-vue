<?php

namespace App\Http\Requests\Api\Client;

use App\Enums\ActiviteCompte;
use App\Enums\StatutCommission;
use App\Support\Periodes\Periode;
use App\Support\Periodes\PeriodeComptableFiltre;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de `GET /v1/mobile/commissions` (ADR 0054). Mêmes périodes que l'accueil : période rapide
 * (`periode`, période entière), période comptable (annee/mois/quinzaine) ou dates libres, exclusives
 * entre elles (422 sinon).
 */
class CommissionsMineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'periode' => ['nullable', Rule::in(DashboardMineRequest::PERIODES), 'prohibits:date_debut,date_fin'],
            ...PeriodeComptableFiltre::regles(['periode', 'date_debut', 'date_fin']),
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'vehicule_id' => ['nullable', 'string'],
            'activite' => ['nullable', Rule::in(ActiviteCompte::values())],
            'statut' => ['nullable', Rule::in(array_column(StatutCommission::cases(), 'value'))],
            'recherche' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{0: ?string, 1: ?string} Dates incluses (Y-m-d), nulles = sans borne. */
    public function bornes(): array
    {
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);

        if ($periodeComptable !== null) {
            return [$periodeComptable->debut->toDateString(), $periodeComptable->fin->toDateString()];
        }

        $cle = $this->input('periode');

        if ($cle !== null) {
            $periode = Periode::rapide($cle, CarbonImmutable::now());

            return [$periode?->debut?->toDateString(), $periode?->fin?->toDateString()];
        }

        return [$this->input('date_debut'), $this->input('date_fin')];
    }

    public function activite(): ?ActiviteCompte
    {
        return ActiviteCompte::tryFrom((string) $this->input('activite'));
    }

    public function recherche(): ?string
    {
        $recherche = trim((string) $this->input('recherche'));

        return $recherche === '' ? null : $recherche;
    }

    public function filters(): array
    {
        [$debut, $fin] = $this->bornes();
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);

        return [
            'periode' => $this->input('periode'),
            'annee' => $periodeComptable?->annee,
            'mois' => $periodeComptable?->mois,
            'quinzaine' => $periodeComptable?->quinzaine,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'vehicule_id' => $this->input('vehicule_id'),
            'activite' => $this->activite()?->value,
            'statut' => $this->input('statut'),
            'recherche' => $this->recherche(),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
