<?php

namespace App\Enums;

/**
 * Nature du client — volontairement indépendante de `cashback_eligible` (éligibilité, pas
 * nature métier). EXTERNE est un client qui vient charger ses propres commandes, hors flotte
 * gérée, tarifé à prix usine (cf. VehiculeCommandeContextResolver). Anciennement nommé
 * PARTENAIRE — renommé pour ne plus entrer en collision avec `Vehicule::categorie` = PARTENAIRE
 * (véhicule tiers mais intégré à la flotte gérée, sens opposé) — voir l'analyse du modèle
 * véhicules/partenaires/commissions.
 */
enum ClientType: string
{
    case STANDARD = 'standard';
    case EXTERNE = 'externe';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard',
            self::EXTERNE => 'Externe',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
