<?php

namespace App\Http\Requests\Api\Client;

use App\Services\Client\Operations\OperationsCompte;
use App\Support\Periodes\Periode;
use App\Support\Periodes\PeriodeComptableFiltre;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de `GET /v1/mobile/activite`, la liste unique « Commandes » (ADR 0058).
 * Aucun filtre sur le statut brut : il permettrait à un livreur de déduire le
 * paiement d'une vente par essais successifs. `lien` restreint aux opérations vues
 * à ce titre (client, livreur, propriétaire), côté serveur pour garder des totaux justes.
 *
 * `periode` (périodes rapides, résolues ici par Periode, jamais par le
 * navigateur) exclut `date_debut`/`date_fin` : 422 plutôt qu'un choix silencieux
 * entre deux périodes contradictoires. La période en cours s'arrête à maintenant.
 *
 * La période comptable (annee/mois/quinzaine) exclut de même `periode` et
 * `date_debut`/`date_fin`.
 */
class ActiviteMineRequest extends FormRequest
{
    public const PERIODES = [
        'aujourd_hui',
        'hier',
        'cette_semaine',
        'semaine_precedente',
        'ce_mois',
        'mois_precedent',
        'cette_annee',
    ];

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['vente', 'logistique'])],
            'lien' => ['nullable', Rule::in([OperationsCompte::LIEN_CLIENT, OperationsCompte::LIEN_LIVREUR, OperationsCompte::LIEN_PROPRIETAIRE])],
            'vehicule_id' => ['nullable', 'string'],
            'periode' => ['nullable', Rule::in(self::PERIODES), 'prohibits:date_debut,date_fin'],
            ...PeriodeComptableFiltre::regles(['periode', 'date_debut', 'date_fin']),
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        [$debut, $fin] = $this->bornes();
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);

        return [
            'type' => $this->input('type'),
            'lien' => $this->input('lien'),
            'vehicule_id' => $this->input('vehicule_id'),
            'periode' => $this->input('periode'),
            'annee' => $periodeComptable?->annee,
            'mois' => $periodeComptable?->mois,
            'quinzaine' => $periodeComptable?->quinzaine,
            'date_debut' => $debut?->toDateString(),
            'date_fin' => $fin?->toDateString(),
        ];
    }

    /**
     * Bornes incluses, à la seconde, dans le fuseau de l'application.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function bornes(): array
    {
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($this);

        if ($periodeComptable !== null) {
            return [$periodeComptable->debut, $periodeComptable->fin];
        }

        $cle = $this->input('periode');

        if ($cle !== null) {
            $maintenant = CarbonImmutable::now();
            $periode = Periode::rapide($cle, $maintenant);

            return [$periode->debut, $periode->fin->min($maintenant)];
        }

        $debut = $this->input('date_debut');
        $fin = $this->input('date_fin');

        return [
            $debut !== null ? CarbonImmutable::parse($debut)->startOfDay() : null,
            $fin !== null ? CarbonImmutable::parse($fin)->endOfDay() : null,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }

    public function page(): int
    {
        return (int) ($this->input('page') ?? 1);
    }
}
