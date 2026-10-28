<?php

namespace App\Enums;

enum TypeVariablePaie: string
{
    case PRIME = 'prime';
    case AUTRE_GAIN = 'autre_gain';
    case AVANCE = 'avance';
    case RETENUE = 'retenue';
    case ABSENCE = 'absence';
    case AUTRE_DEDUCTION = 'autre_deduction';

    public function label(): string
    {
        return match ($this) {
            self::PRIME => 'Prime',
            self::AUTRE_GAIN => 'Autre gain',
            self::AVANCE => 'Avance sur salaire',
            self::RETENUE => 'Retenue',
            self::ABSENCE => 'Absence',
            self::AUTRE_DEDUCTION => 'Autre déduction',
        };
    }

    public function estDeduction(): bool
    {
        return in_array($this, [self::AVANCE, self::RETENUE, self::ABSENCE, self::AUTRE_DEDUCTION], true);
    }

    public function estGain(): bool
    {
        return ! $this->estDeduction();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Effets qu'un type de dépense peut avoir sur la paie : des retenues uniquement. Une prime ou un
     * autre gain vient du référentiel RH, jamais d'une dépense (décision du 26/10/2026, docs/paie.md).
     *
     * @return list<self>
     */
    public static function retenues(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->estDeduction()));
    }

    /** @return list<string> */
    public static function valeursRetenues(): array
    {
        return array_map(fn (self $case) => $case->value, self::retenues());
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
