<?php

namespace App\Support\Commission;

use App\Services\PeriodeComptableService;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Filtre "Période" des listes de commissions (Livreurs, Propriétaires, Agences, Consultants) :
 * l'utilisateur peut cocher UNE ou PLUSIEURS périodes (décision produit du 18/10/2026). Plusieurs
 * périodes s'unissent — une ligne est retenue dès que sa date tombe dans l'une d'elles, jamais
 * une intersection. Aucune sélection = toutes les périodes.
 *
 * Le même jeu de codes alimente la liste, les dépenses déduites, la fiche payable et les exports :
 * jamais une période appliquée aux commissions et une autre aux dépenses.
 */
class CommissionPeriodeFilter
{
    private const FORMAT_CODE = '/^\d{4}-\d{2}-(P1|P2|M)$/';

    /**
     * Normalise ?periode=2026-09-P1 (scalaire historique, liens et vues enregistrées) comme
     * ?periode[]=2026-09-P1&periode[]=2026-09-P2 (DataFilters) en tableau de codes valides,
     * sans doublon. Un code mal formé est écarté, jamais transmis à dateRangeForCode().
     *
     * @return array<int, string>
     */
    public static function normaliserCodes(array|string|null $input): array
    {
        return collect(is_array($input) ? $input : [$input])
            ->filter(fn ($code) => is_string($code))
            ->map(fn (string $code) => trim($code))
            ->filter(fn (string $code) => preg_match(self::FORMAT_CODE, $code) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Restreint $colonne à l'union des plages des périodes — sans effet si $codes est vide.
     * $colonneDate : colonne de type date (ex. date_depense), comparée sur les jours entiers.
     *
     * @param  array<int, string>  $codes
     */
    public static function appliquer(Builder $query, array $codes, string $colonne, bool $colonneDate = false): Builder
    {
        if ($codes === []) {
            return $query;
        }

        return $query->where(function ($q) use ($codes, $colonne, $colonneDate) {
            foreach ($codes as $code) {
                [$debut, $fin] = PeriodeComptableService::dateRangeForCode($code);
                $q->orWhereBetween($colonne, $colonneDate
                    ? [$debut->toDateString(), $fin->toDateString().' 23:59:59']
                    : [$debut, $fin]);
            }
        });
    }

    /**
     * Restreint une requête de PaiementPeriode (date_debut/date_fin) aux périodes qui chevauchent
     * au moins un des codes — sans effet si $codes est vide.
     *
     * @param  array<int, string>  $codes
     */
    public static function appliquerChevauchement(Builder $query, array $codes): Builder
    {
        if ($codes === []) {
            return $query;
        }

        return $query->where(function ($q) use ($codes) {
            foreach ($codes as $code) {
                [$debut, $fin] = PeriodeComptableService::dateRangeForCode($code);
                $q->orWhere(fn ($sub) => $sub
                    ->where('date_debut', '<=', $fin->toDateString())
                    ->where('date_fin', '>=', $debut->toDateString()));
            }
        });
    }

    /** @param  array<int, string>  $codes */
    public static function label(array $codes): string
    {
        return $codes === []
            ? 'Toutes périodes'
            : implode(', ', array_map(PeriodeComptableService::labelForCode(...), $codes));
    }

    /**
     * Code unique sélectionné, ou null si aucune ou plusieurs périodes sont cochées — pour les
     * éléments qui n'ont de sens que pour UNE période (statut de période affiché, lien de
     * répartition).
     *
     * @param  array<int, string>  $codes
     */
    public static function codeUnique(array $codes): ?string
    {
        return count($codes) === 1 ? $codes[0] : null;
    }
}
