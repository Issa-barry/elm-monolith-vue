<?php

namespace App\Enums;

/**
 * Nature du client — volontairement indépendante de `cashback_eligible` (éligibilité, pas
 * nature métier) et de la dérogation d'impayés. EXTERNE est un client qui vient charger ses
 * propres commandes, hors flotte gérée, tarifé à prix usine par défaut (cf.
 * VehiculeCommandeContextResolver) — anciennement nommé PARTENAIRE, renommé pour ne plus entrer
 * en collision avec `Vehicule::categorie` = PARTENAIRE (sens opposé).
 *
 * REVENDEUR remplace l'ancien STANDARD (migration `migrate_client_type_standard_to_revendeur`,
 * 28/08/2026) — automatiquement éligible au cashback (cf. ClientController). DISTRIBUTEUR est une
 * nature neuve, sans avantage automatique (ni cashback, ni dérogation, ni prix usine) sauf règle
 * explicite. Les trois natures ont chacune leur propre tarif sur les produits fabricables
 * (prix_externe/prix_revendeur/prix_distributeur, cf. PrixVenteNatureResolver).
 */
enum ClientType: string
{
    case EXTERNE = 'externe';
    case REVENDEUR = 'revendeur';
    case DISTRIBUTEUR = 'distributeur';

    public function label(): string
    {
        return match ($this) {
            self::EXTERNE => 'Externe',
            self::REVENDEUR => 'Revendeur',
            self::DISTRIBUTEUR => 'Distributeur',
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
