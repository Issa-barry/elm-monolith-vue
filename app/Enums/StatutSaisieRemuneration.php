<?php

namespace App\Enums;

/**
 * Montant (ou taux) du mois d'un élément « saisi chaque mois » (docs/primes-avantages.md) : il
 * n'est repris dans la transmission du mois qu'une fois validé.
 */
enum StatutSaisieRemuneration: string
{
    case A_VALIDER = 'a_valider';
    case VALIDEE = 'validee';

    public function label(): string
    {
        return match ($this) {
            self::A_VALIDER => 'À valider',
            self::VALIDEE => 'Validée',
        };
    }
}
