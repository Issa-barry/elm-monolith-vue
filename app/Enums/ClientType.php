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
 *
 * GROSSISTE (ajouté 05/09/2026) est une nature distincte, volontairement PAS un sous-type de
 * DISTRIBUTEUR : achète en grande quantité, au choix Enlèvement (retrait usine, sans véhicule de
 * flotte) ou Livraison (véhicule de flotte), décidé par commande via
 * `CommandeVente::mode_remise_grossiste` — jamais une caractéristique fixe du client. Son tarif ne
 * suit PAS le patron prix_externe/prix_revendeur/prix_distributeur (colonnes par variante) : il
 * dépend de la catégorie commerciale du produit ET du mode de remise, résolu par
 * `GrossisteTarifResolver` via la table `categorie_tarifs_grossiste` — jamais une colonne
 * supplémentaire sur `produit_variantes`, cf. docs/grossiste.md.
 */
enum ClientType: string
{
    case EXTERNE = 'externe';
    case REVENDEUR = 'revendeur';
    case DISTRIBUTEUR = 'distributeur';
    case GROSSISTE = 'grossiste';

    public function label(): string
    {
        return match ($this) {
            self::EXTERNE => 'Externe',
            self::REVENDEUR => 'Revendeur',
            self::DISTRIBUTEUR => 'Distributeur',
            self::GROSSISTE => 'Grossiste',
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
