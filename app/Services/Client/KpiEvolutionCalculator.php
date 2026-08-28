<?php

namespace App\Services\Client;

use App\Enums\KpiEvolutionDirection;
use App\Services\Client\Data\KpiEvolution;

/**
 * Formule UNIQUE de comparaison de période pour tous les KPI du dashboard
 * espace client (cf. rapport du 27/08/2026) — centralisée ici pour ne
 * jamais la dupliquer par KPI (`ClientEarningsService::summaryEvolution()`
 * l'appelle une fois par champ de `calculateEarnings()`).
 *
 * Cas particulier volontaire : `previous == 0`.
 * - `current == 0` aussi → 0 % / stable / comparable (deux zéros ne sont
 *   pas mathématiquement "incomparables", ils sont juste identiques).
 * - `current > 0` → le pourcentage n'est PAS défini mathématiquement
 *   (division par zéro) ; on ne retourne jamais `Infinity`/`999999`/`100`
 *   en substitut. `percent` devient `null`, `comparable` devient `false`,
 *   `direction` reste renseignée factuellement pour que le frontend puisse
 *   afficher une flèche (généralement accompagnée d'un texte comme
 *   "Nouveau" plutôt que d'un pourcentage).
 */
final class KpiEvolutionCalculator
{
    private const ROUND_PRECISION = 1;

    public static function compare(float $current, float $previous): KpiEvolution
    {
        $previousIsZero = $previous === 0.0;
        $comparable = ! ($previousIsZero && $current !== 0.0);

        $percent = match (true) {
            $previousIsZero && $current === 0.0 => 0.0,
            $previousIsZero => null,
            default => round((($current - $previous) / $previous) * 100, self::ROUND_PRECISION),
        };

        $direction = match (true) {
            $previousIsZero && $current === 0.0 => KpiEvolutionDirection::STABLE,
            $previousIsZero => $current > 0.0 ? KpiEvolutionDirection::UP : KpiEvolutionDirection::DOWN,
            $percent > 0.0 => KpiEvolutionDirection::UP,
            $percent < 0.0 => KpiEvolutionDirection::DOWN,
            default => KpiEvolutionDirection::STABLE,
        };

        return new KpiEvolution(
            previousValue: $previousIsZero ? 0.0 : $previous,
            percent: $percent,
            direction: $direction,
            comparable: $comparable,
        );
    }
}
