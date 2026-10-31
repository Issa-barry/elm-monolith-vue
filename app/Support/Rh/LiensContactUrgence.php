<?php

namespace App\Support\Rh;

/**
 * Lien entre le salarié et son contact d'urgence (docs/rh-employes-identite.md). La colonne garde
 * le libellé choisi ; pour « Autre », elle garde la précision saisie. Une ancienne valeur libre
 * absente de la liste se relit donc comme « Autre » avec cette valeur pour précision.
 */
final class LiensContactUrgence
{
    public const AUTRE = 'Autre';

    public const LISTE = [
        'Conjoint(e)', 'Père', 'Mère', 'Frère', 'Sœur', 'Fils', 'Fille', 'Oncle', 'Tante',
        'Cousin(e)', 'Ami(e)', 'Collègue', 'Tuteur / Tutrice',
    ];

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $l) => ['value' => $l, 'label' => $l], [...self::LISTE, self::AUTRE]);
    }

    /** Valeur à enregistrer : le libellé, ou la précision pour « Autre ». */
    public static function valeur(?string $lien, ?string $precision): ?string
    {
        if (blank($lien)) {
            return null;
        }

        return $lien === self::AUTRE ? trim((string) $precision) : $lien;
    }
}
