<?php

namespace App\Enums;

/**
 * Portée catalogue d'une commission_regle — cf. CommissionRegleResolver.
 * Résolution stricte, sans héritage : variante > produit > categorie > global.
 */
enum CommissionScopeType: string
{
    case VARIANTE = 'variante';
    case PRODUIT = 'produit';
    case CATEGORIE = 'categorie';
    case GLOBAL = 'global';

    public function label(): string
    {
        return match ($this) {
            self::VARIANTE => 'Variante',
            self::PRODUIT => 'Produit',
            self::CATEGORIE => 'Catégorie',
            self::GLOBAL => 'Globale',
        };
    }
}
