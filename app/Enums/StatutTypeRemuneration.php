<?php

namespace App\Enums;

/**
 * Cycle de vie d'un type de prime ou d'avantage (docs/primes-avantages.md, ADR 0038) :
 * à valider → actif → fermé ou inactif → réactivation vers actif. Seul un type actif peut être
 * attribué à un salarié.
 */
enum StatutTypeRemuneration: string
{
    case A_VALIDER = 'a_valider';
    case ACTIF = 'actif';
    // Plus de nouvelle attribution ; les bénéficiaires actuels sont maintenus (lot 3).
    case FERME = 'ferme';
    case INACTIF = 'inactif';

    public function label(): string
    {
        return match ($this) {
            self::A_VALIDER => 'À valider',
            self::ACTIF => 'Actif',
            self::FERME => 'Fermé aux nouvelles attributions',
            self::INACTIF => 'Inactif',
        };
    }

    /** A été validé au moins une fois : sa modification et sa suppression exigent le droit de validation. */
    public function estValide(): bool
    {
        return $this !== self::A_VALIDER;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
