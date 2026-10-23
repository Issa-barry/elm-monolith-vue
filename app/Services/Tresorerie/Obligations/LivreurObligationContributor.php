<?php

namespace App\Services\Tresorerie\Obligations;

use App\Enums\TypePeriodePaiement;

/** Commissions livreurs, payées à la quinzaine (colonnes livreurs_p1 / livreurs_p2). */
class LivreurObligationContributor extends FichesQuinzaineObligationContributor
{
    protected function type(): TypePeriodePaiement
    {
        return TypePeriodePaiement::LIVREUR;
    }

    protected function prefixe(): string
    {
        return 'livreurs';
    }
}
