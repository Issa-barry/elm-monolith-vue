<?php

namespace App\Enums;

/**
 * Origine du prix réellement appliqué à une ligne de commande — snapshotée à la création (cf.
 * CommandeVenteLigne::prix_origine_snapshot) pour que l'écran "Prix appliqué" reste exact même
 * après un changement ultérieur des tarifs du produit ou de la nature du client. Ne recalcule
 * jamais l'historique : cette valeur est figée au moment de la vente, comme les montants
 * eux-mêmes (prix_usine_snapshot / prix_vente_snapshot).
 */
enum PrixOrigine: string
{
    case USINE = 'usine';
    case VENTE = 'vente';
    case EXTERNE = 'externe';
    case REVENDEUR = 'revendeur';
    case DISTRIBUTEUR = 'distributeur';

    public function label(): string
    {
        return match ($this) {
            self::USINE => 'Prix usine',
            self::VENTE => 'Prix vente',
            self::EXTERNE => 'Prix externe',
            self::REVENDEUR => 'Prix revendeur',
            self::DISTRIBUTEUR => 'Prix distributeur',
        };
    }
}
