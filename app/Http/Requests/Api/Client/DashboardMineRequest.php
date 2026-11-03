<?php

namespace App\Http\Requests\Api\Client;

use App\Services\Client\ClientEarningsService;
use App\Support\Periodes\PeriodeComptableFiltre;
use App\Support\Vehicules\SituationPeriode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Règles identiques au dashboard Inertia — définies UNE FOIS sur
 * ClientEarningsService::filterValidationRules(), jamais dupliquées ici.
 * Uniquement pour que ces filtres soient visibles dans la doc OpenAPI générée
 * (query params typés, 422 documenté) — `ClientEarningsService::resolveFilters()`
 * continue de (re)valider en interne, ce type-hint ne change aucun calcul.
 *
 * Seule exception, propre à l'API : le filtre de période de l'accueil, soit une
 * période rapide (`periode`, résolue par SituationPeriode), soit une période
 * comptable (annee/mois/quinzaine). Ils s'excluent, et excluent
 * period/date_debut/date_fin.
 */
class DashboardMineRequest extends FormRequest
{
    public const PERIODES = [
        'aujourd_hui',
        'hier',
        'cette_semaine',
        'semaine_precedente',
        'ce_mois',
        'mois_precedent',
        'cette_annee',
        'annee_precedente',
        SituationPeriode::TOUT,
    ];

    public function rules(): array
    {
        return [
            ...ClientEarningsService::filterValidationRules(),
            'periode' => ['nullable', Rule::in(self::PERIODES), 'prohibits:period,date_debut,date_fin'],
            ...PeriodeComptableFiltre::regles(['periode', 'period', 'date_debut', 'date_fin']),
        ];
    }

    /**
     * Bornes du filtre de période de l'accueil (nulles pour « toutes les périodes »),
     * ou null si la requête ne l'utilise pas.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}|null
     */
    public function bornesPeriode(): ?array
    {
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);

        if ($periodeComptable !== null) {
            return [$periodeComptable->debut, $periodeComptable->fin];
        }

        $cle = $this->input('periode');

        if ($cle === null) {
            return null;
        }

        $periode = SituationPeriode::rapide($cle, CarbonImmutable::now());

        return [$periode?->debut, $periode?->fin];
    }
}
