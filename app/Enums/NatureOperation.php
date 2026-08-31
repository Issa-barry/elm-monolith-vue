<?php

namespace App\Enums;

/**
 * Nature commerciale d'une CommandeVente — figée à la création, jamais recalculée si le client
 * change de type ensuite (cf. deriverParDefaut(), seule source de vérité de la dérivation, appelée
 * à la fois par CommandeVenteController::store() et PdvCheckoutService::checkout()).
 *
 * Distincte de ClientType : un client DISTRIBUTEUR retirant lui-même sa commande sans véhicule de
 * flotte reste VENTE_STANDARD (aucune équipe de livraison à commissionner) — la nature dépend du
 * type de client ET de la présence d'un véhicule, jamais du seul type de client.
 */
enum NatureOperation: string
{
    case VENTE_STANDARD = 'vente_standard';
    case DISTRIBUTION_CLIENT = 'distribution_client';

    public function label(): string
    {
        return match ($this) {
            self::VENTE_STANDARD => 'Vente',
            self::DISTRIBUTION_CLIENT => 'Distribution client',
        };
    }

    /**
     * Préfixe de référence métier (cf. App\Services\ReferenceNumeroService) — décision produit du
     * 31/08/2026 : les anciennes commandes gardent leur référence `CMD-...` telle quelle, seules
     * les nouvelles commandes créées après ce chantier reçoivent VTE-/DST-.
     */
    public function prefixeReference(): string
    {
        return match ($this) {
            self::VENTE_STANDARD => 'VTE',
            self::DISTRIBUTION_CLIENT => 'DST',
        };
    }

    /**
     * DISTRIBUTION_CLIENT seulement si le client est un distributeur ET qu'un véhicule de flotte
     * assure la livraison — sans véhicule, aucune équipe à commissionner en distribution, la
     * commande reste une vente standard (au tarif distributeur, cf. PrixVenteNatureResolver).
     */
    public static function deriverParDefaut(?ClientType $clientType, ?string $vehiculeId): self
    {
        return $clientType === ClientType::DISTRIBUTEUR && $vehiculeId !== null
            ? self::DISTRIBUTION_CLIENT
            : self::VENTE_STANDARD;
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
