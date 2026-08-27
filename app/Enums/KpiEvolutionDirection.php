<?php

namespace App\Enums;

/**
 * Direction FACTUELLE d'une évolution de KPI entre deux périodes (cf.
 * `App\Services\Client\KpiEvolutionCalculator`) — jamais un jugement
 * métier ("succès"/"danger"). Une hausse de dépenses est `up`, exactement
 * comme une hausse de commission : c'est au frontend de décider, KPI par
 * KPI, si une hausse donnée est une bonne ou une mauvaise nouvelle.
 */
enum KpiEvolutionDirection: string
{
    case UP = 'up';
    case DOWN = 'down';
    case STABLE = 'stable';
}
