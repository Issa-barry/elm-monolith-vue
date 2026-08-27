<?php

namespace App\Services\Client\Data;

use App\Enums\KpiEvolutionDirection;
use JsonSerializable;

/**
 * Évolution d'un KPI entre la période sélectionnée et la période
 * immédiatement précédente de même durée — cf.
 * `App\Services\Client\KpiEvolutionCalculator::compare()` pour la formule et
 * `docs/api-espace-client-contract.md` pour la règle de comparaison.
 *
 * `percent` est `null` uniquement quand `comparable` est `false` (valeur
 * précédente à 0 et valeur actuelle non nulle) : mathématiquement, ce
 * pourcentage n'est pas défini — jamais `Infinity`/`999999`/`100` en
 * substitut. `direction` reste renseignée dans ce cas (`up`/`down` selon le
 * sens réel du changement) pour que le frontend puisse quand même afficher
 * une flèche, généralement accompagnée d'un texte du type "Nouveau" plutôt
 * que d'un pourcentage.
 */
final class KpiEvolution implements JsonSerializable
{
    public function __construct(
        public readonly float $previousValue,
        public readonly ?float $percent,
        public readonly KpiEvolutionDirection $direction,
        public readonly bool $comparable,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'previous_value' => $this->previousValue,
            'percent' => $this->percent,
            'direction' => $this->direction,
            'comparable' => $this->comparable,
        ];
    }
}
