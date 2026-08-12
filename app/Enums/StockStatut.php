<?php

namespace App\Enums;

/**
 * État de disponibilité calculé pour un couple Variante × Site — jamais persisté (cf.
 * StockStatutService), toujours recalculé à partir de la quantité réelle et du seuil effectif
 * du produit. Remplace l'ancien champ manuel `produits.is_alerte`.
 */
enum StockStatut: string
{
    case DISPONIBLE = 'disponible';
    case STOCK_FAIBLE = 'stock_faible';
    case RUPTURE = 'rupture';

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::STOCK_FAIBLE => 'Stock faible',
            self::RUPTURE => 'Rupture de stock',
        };
    }
}
