<?php

namespace App\Services\Tresorerie\Obligations;

use App\Enums\TypePeriodePaiement;

/**
 * Commissions des parrains de véhicule (ADR 0035), payées à la quinzaine par l'agence du véhicule
 * (colonnes parrains_p1 / parrains_p2).
 */
class ParrainObligationContributor extends FichesQuinzaineObligationContributor
{
    protected function type(): TypePeriodePaiement
    {
        return TypePeriodePaiement::PARRAIN;
    }

    protected function prefixe(): string
    {
        return 'parrains';
    }
}
