<?php

namespace App\Enums;

use App\Models\Vehicule;

/**
 * Nature commerciale d'une CommandeVente — figée à la création, jamais recalculée si le client
 * change de type ensuite (cf. deriverParDefaut(), seule source de vérité de la dérivation, appelée
 * à la fois par CommandeVenteController::store() et PdvCheckoutService::checkout()).
 *
 * Distincte de ClientType : un client DISTRIBUTEUR retirant lui-même sa commande sans véhicule de
 * flotte reste VENTE_STANDARD (aucune équipe de livraison à commissionner) — la nature dépend du
 * type de client ET de la présence d'un véhicule AUTORISÉ POUR LA LOGISTIQUE (décision produit du
 * 31/08/2026 — un véhicule vente-only ne fait jamais basculer en distribution), jamais du seul
 * type de client.
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
     * DISTRIBUTION_CLIENT seulement si le client est un distributeur ET qu'un véhicule
     * effectivement autorisé pour la logistique (`Vehicule::livraison_logistique = true`)
     * assure la livraison — sans véhicule, ou avec un véhicule vente-only, aucune équipe de
     * livraison ELM à commissionner en distribution, la commande reste une vente standard (au
     * tarif distributeur, cf. PrixVenteNatureResolver). Révisé le 31/08/2026 : vérifiait
     * auparavant seulement la présence d'un véhicule, quel que soit son usage autorisé.
     */
    public static function deriverParDefaut(?ClientType $clientType, ?Vehicule $vehicule): self
    {
        return $clientType === ClientType::DISTRIBUTEUR && (bool) $vehicule?->livraison_logistique
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
