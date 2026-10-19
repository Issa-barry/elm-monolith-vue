<?php

namespace App\Support\Commission;

use App\Enums\StatutCommission;
use Illuminate\Support\Collection;

/**
 * Filtre "Statut" des listes de commissions : UN ou PLUSIEURS statuts cochés s'unissent
 * (décision produit du 18/10/2026) — une ligne est retenue si son statut global est l'un d'eux.
 * Aucune sélection = tous les statuts.
 */
class CommissionStatutFilter
{
    /**
     * Normalise ?statut=impaye (scalaire historique) comme ?statut[]=impaye&statut[]=partiel en
     * tableau de valeurs StatutCommission valides, sans doublon.
     *
     * @return array<int, string>
     */
    public static function normaliser(array|string|null $input): array
    {
        $valides = array_column(StatutCommission::cases(), 'value');

        return collect(is_array($input) ? $input : [$input])
            ->filter(fn ($statut) => is_string($statut))
            ->map(fn (string $statut) => trim($statut))
            ->filter(fn (string $statut) => in_array($statut, $valides, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @param  array<int, string>  $statuts
     * @return Collection<int, array<string, mixed>>
     */
    public static function appliquer(Collection $lignes, array $statuts, string $cle = 'statut_global'): Collection
    {
        return $statuts === []
            ? $lignes
            : $lignes->filter(fn (array $ligne) => in_array($ligne[$cle], $statuts, true));
    }
}
