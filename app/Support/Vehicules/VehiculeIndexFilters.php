<?php

namespace App\Support\Vehicules;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Filtrage de la liste et des vues sur les mêmes lignes, après résolution des partages. */
final class VehiculeIndexFilters
{
    public static function apply(Collection $vehicules, array $filters): Collection
    {
        return $vehicules->filter(function (array $v) use ($filters): bool {
            if (! empty($filters['site_ids']) && ! in_array($v['site_id'], $filters['site_ids'], true)) {
                return false;
            }
            if (! empty($filters['type_vehicule_id']) && $v['type_vehicule_id'] !== $filters['type_vehicule_id']) {
                return false;
            }
            if (! empty($filters['statut']) && $v['is_active'] !== ($filters['statut'] === 'actif')) {
                return false;
            }
            $usage = $filters['usage'] ?? '';
            if (($usage === 'vente' && ! $v['livraison_vente'])
                || ($usage === 'logistique' && ! $v['livraison_logistique'])
                || ($usage === 'aucun' && ($v['livraison_vente'] || $v['livraison_logistique']))) {
                return false;
            }
            $agence = $filters['agence_proprietaire_id'] ?? '';
            if ($agence !== '' && ($agence === '__none__' ? $v['agence_id'] !== null : $v['agence_id'] !== $agence)) {
                return false;
            }
            $partage = $filters['partage'] ?? '';
            $aFaire = count(array_intersect($v['partages_commission'], ['a_faire', 'sans_equipe'])) > 0;
            if ($partage !== '' && ($partage === 'a_faire' ? ! $aFaire : $aFaire)) {
                return false;
            }

            $search = Str::lower(trim($filters['nom'] ?? ''));
            if ($search === '') {
                return true;
            }
            foreach (['nom_vehicule', 'immatriculation', 'type_label', 'proprietaire_nom', 'agence_nom', 'equipe_nom'] as $key) {
                if (str_contains(Str::lower($v[$key] ?? ''), $search)) {
                    return true;
                }
            }
            $digits = preg_replace('/\D/', '', $search);
            if ($digits !== '' && str_contains(preg_replace('/\D/', '', $v['proprietaire_telephone'] ?? ''), $digits)) {
                return true;
            }

            return collect($v['capacites'])->contains(fn ($c) => str_contains((string) $c['capacite_max'], $search));
        })->values();
    }

    public static function stats(Collection $vehicules): array
    {
        $actifs = $vehicules->where('is_active', true)->count();

        return [
            'total' => $vehicules->count(),
            'actifs' => $actifs,
            'inactifs' => $vehicules->count() - $actifs,
            'sansEquipe' => $vehicules->whereNull('equipe_nom')->count(),
            'parType' => $vehicules->countBy('type_label')->sortDesc()
                ->map(fn ($count, $label) => compact('label', 'count'))->values()->all(),
        ];
    }
}
