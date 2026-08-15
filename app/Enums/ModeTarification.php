<?php

namespace App\Enums;

/**
 * Détermine, au moment de la création d'une commande de vente, quel prix sert de base au
 * montant à encaisser par l'usine (et non le prix affiché au client) :
 *  - PRIX_VENTE : véhicule de flotte gérée (toute ligne présente dans `vehicules` l'est par
 *    définition) → l'usine encaisse le plein prix de vente, la marge lui revient et alimente
 *    les commissions.
 *  - PRIX_USINE : vente client EXTERNE (Client::type = EXTERNE, avec ou sans véhicule) →
 *    l'usine ne récupère que son prix usine, la marge reste au client externe qui revend,
 *    aucune commission n'est générée sur cette commande (pas de véhicule de flotte).
 *
 * La dérivation elle-même vit dans VehiculeCommandeContextResolver — cet enum ne fait que
 * décrire les deux valeurs possibles.
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

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
