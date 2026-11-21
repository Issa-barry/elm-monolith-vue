<?php

namespace App\Support\Vehicules;

use Collator;
use Illuminate\Support\Collection;

/**
 * Liste des véhicules paginée côté serveur : 20 lignes par page, tri des colonnes triables du
 * tableau sur l'ensemble des véhicules filtrés.
 *
 * Le tri reproduit celui que faisait le tableau dans le navigateur (PrimeVue DataTable) :
 * textes comparés comme Intl.Collator numérique (« Camion 2 » avant « Camion 10 »), valeurs vides
 * toujours en dernier quel que soit le sens, égalités dans l'ordre de départ (nom du véhicule).
 */
final class ListeVehicules
{
    /** Colonnes triables du tableau (clé = champ de la ligne). */
    public const TRIS = ['nom_vehicule', 'type_label', 'usage_label', 'is_active'];

    public const PAR_PAGE = 20;

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes  dans l'ordre par défaut (nom du véhicule)
     * @return Collection<int, array<string, mixed>>
     */
    public static function trier(Collection $lignes, ?string $tri, string $sens): Collection
    {
        if ($tri === null) {
            return $lignes->values();
        }

        $ordre = $sens === 'asc' ? 1 : -1;
        $textes = self::comparateurTextes();

        // Collection::sort() est stable (PHP 8) : les égalités gardent l'ordre de départ.
        return $lignes->sort(function (array $a, array $b) use ($tri, $ordre, $textes): int {
            $va = $a[$tri] ?? null;
            $vb = $b[$tri] ?? null;
            $videA = $va === null || $va === '';
            $videB = $vb === null || $vb === '';

            if ($videA || $videB) {
                return $videA <=> $videB;
            }

            return $ordre * (is_string($va) && is_string($vb) ? $textes($va, $vb) : $va <=> $vb);
        })->values();
    }

    /** @return callable(string, string): int */
    private static function comparateurTextes(): callable
    {
        if (class_exists(Collator::class)) {
            $collator = new Collator('fr_FR');
            $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

            return fn (string $a, string $b): int => (int) $collator->compare($a, $b);
        }

        // Sans l'extension intl : ordre naturel insensible à la casse (accents non pondérés).
        return fn (string $a, string $b): int => strnatcasecmp($a, $b);
    }
}
