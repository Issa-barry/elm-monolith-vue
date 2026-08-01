<?php

namespace App\Enums;

/**
 * Détermine, au moment de la création d'une commande de vente, quel prix
 * sert de base au montant à encaisser par l'usine (et non le prix affiché
 * au client) :
 *  - PRIX_VENTE : véhicule pris en charge par l'usine → l'usine encaisse
 *    le plein prix de vente, la marge lui revient et alimente les commissions.
 *  - PRIX_USINE : véhicule non pris en charge → l'usine ne récupère que son
 *    prix usine, la marge reste à l'exploitant externe, aucune commission
 *    n'est générée par l'usine sur cette commande.
 */
enum ModeTarification: string
{
    case PRIX_VENTE = 'prix_vente';
    case PRIX_USINE = 'prix_usine';

    public function label(): string
    {
        return match ($this) {
            self::PRIX_VENTE => 'Prix de vente',
            self::PRIX_USINE => 'Prix usine',
        };
    }

    public static function fromPrisEnChargeParUsine(bool $prisEnCharge): self
    {
        return $prisEnCharge ? self::PRIX_VENTE : self::PRIX_USINE;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
