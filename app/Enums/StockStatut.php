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
    // Stock < 0 : vente(s) acceptée(s) au-delà du disponible — organisation avec le paramètre
    // global "Autoriser les ventes sans stock disponible" actif (cf. Parametre::
    // isVentesAutoriseesSansStock(), MouvementStockService::appliquer()). Jamais confondu avec
    // RUPTURE (= 0) — la quantité négative reste affichée telle quelle, jamais ramenée à 0
    // (cf. décision produit du 23/08/2026).
    case STOCK_NEGATIF = 'stock_negatif';

    public function label(): string
    {
        return match ($this) {
            self::DISPONIBLE => 'Disponible',
            self::STOCK_FAIBLE => 'Stock faible',
            self::RUPTURE => 'Rupture de stock',
            self::STOCK_NEGATIF => 'Stock négatif',
        };
    }
}
