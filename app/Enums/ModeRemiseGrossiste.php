<?php

namespace App\Enums;

/**
 * Mode de remise d'une commande Grossiste — porté par `CommandeVente::mode_remise_grossiste`,
 * PAR COMMANDE et jamais par le client (un même Grossiste peut être en Livraison une fois et en
 * Enlèvement une autre, cf. docs/grossiste.md). Gouverne à la fois le tarif appliqué
 * (GrossisteTarifResolver, catégorie × mode) et l'obligation de véhicule :
 * ENLEVEMENT → aucun véhicule de flotte (le client retire lui-même, chemin
 * `CommandeVenteService::creerFactureDirecte()`) ; LIVRAISON → véhicule de flotte obligatoire,
 * workflow standard inchangé (`CommandeVenteService::confirmer()`).
 *
 * Sans rapport avec `ModeTarification` (PRIX_VENTE/PRIX_USINE, véhicule/nature client) : les deux
 * notions coexistent, un Grossiste résout toujours son prix via GrossisteTarifResolver, jamais via
 * ModeTarification.
 */
enum ModeRemiseGrossiste: string
{
    case ENLEVEMENT = 'enlevement';
    case LIVRAISON = 'livraison';

    public function label(): string
    {
        return match ($this) {
            self::ENLEVEMENT => 'Enlèvement usine',
            self::LIVRAISON => 'Livraison',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
