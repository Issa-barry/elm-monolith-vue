<?php

namespace App\Enums;

enum StatutPeriodePaie: string
{
    case BROUILLON  = 'brouillon';
    case CALCULE    = 'calcule';
    case VALIDE_RH  = 'valide_rh';
    case PAYE       = 'paye';
    case CLOTURE    = 'cloture';

    public function label(): string
    {
        return match($this) {
            self::BROUILLON => 'Brouillon',
            self::CALCULE   => 'Calculé',
            self::VALIDE_RH => 'Validé RH',
            self::PAYE      => 'Payé',
            self::CLOTURE   => 'Clôturé',
        };
    }

    /** Transitions autorisées depuis ce statut */
    public function transitionsAutorisees(): array
    {
        return match($this) {
            self::BROUILLON => [self::CALCULE],
            self::CALCULE   => [self::BROUILLON, self::VALIDE_RH],
            self::VALIDE_RH => [self::CALCULE, self::PAYE],
            self::PAYE      => [self::CLOTURE],
            self::CLOTURE   => [],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    /** Modifications sensibles (variables, recalcul) interdites après ce seuil */
    public function estVerrouille(): bool
    {
        return in_array($this, [self::VALIDE_RH, self::PAYE, self::CLOTURE], true);
    }

    public function estCloture(): bool
    {
        return $this === self::CLOTURE;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
