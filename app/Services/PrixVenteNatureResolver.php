<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Enums\PrixOrigine;
use App\Models\Client;
use App\Models\ProduitVariante;

/**
 * Point d'entrée unique pour déterminer le prix de vente applicable à une ligne de commande
 * selon la nature du client (Externe/Revendeur/Distributeur) — réservé aux produits fabricables
 * (cf. ProduitType::code), indépendant de prix_usine et du mode de tarification véhicule
 * (VehiculeCommandeContextResolver, inchangé pour les produits non-fabricable).
 *
 * Toujours source de vérité serveur, jamais le prix envoyé par le frontend (CommandeVenteController
 * l'ignore pour ces lignes, PdvCheckoutService ne l'a jamais reçu) — cf. rapport du 28/08/2026.
 *
 * NULL sur le tarif spécifique (prix_externe/prix_revendeur/prix_distributeur) → repli sur
 * prix_vente, jamais confondu avec 0 (un tarif à 0 GNF explicitement enregistré est respecté).
 */
class PrixVenteNatureResolver
{
    public const TYPE_CODE_FABRICABLE = 'fabricable';

    public static function estFabricable(ProduitVariante $variante): bool
    {
        return $variante->produit?->produitType?->code === self::TYPE_CODE_FABRICABLE;
    }

    public static function resolve(ProduitVariante $variante, ?Client $client): int
    {
        $prixVenteDefaut = (int) ($variante->prix_vente ?? 0);

        if (! $client || ! self::estFabricable($variante)) {
            return $prixVenteDefaut;
        }

        $tarifNature = match ($client->type) {
            ClientType::EXTERNE => $variante->prix_externe,
            ClientType::REVENDEUR => $variante->prix_revendeur,
            ClientType::DISTRIBUTEUR => $variante->prix_distributeur,
        };

        return $tarifNature !== null ? (int) $tarifNature : $prixVenteDefaut;
    }

    /**
     * Origine du montant que resolve() vient de calculer — jamais déduite après coup du montant
     * lui-même (deux natures ou le prix de vente peuvent coïncider par coïncidence). Reflète le
     * repli réel : si le tarif de la nature du client n'est pas configuré, l'origine est 'vente'
     * (pas la nature), pour ne jamais afficher "Prix distributeur" sur un montant qui est en
     * réalité le prix de vente par défaut.
     */
    public static function resolveOrigine(ProduitVariante $variante, ?Client $client): PrixOrigine
    {
        if (! $client || ! self::estFabricable($variante)) {
            return PrixOrigine::VENTE;
        }

        $tarifNature = match ($client->type) {
            ClientType::EXTERNE => $variante->prix_externe,
            ClientType::REVENDEUR => $variante->prix_revendeur,
            ClientType::DISTRIBUTEUR => $variante->prix_distributeur,
        };

        if ($tarifNature === null) {
            return PrixOrigine::VENTE;
        }

        return match ($client->type) {
            ClientType::EXTERNE => PrixOrigine::EXTERNE,
            ClientType::REVENDEUR => PrixOrigine::REVENDEUR,
            ClientType::DISTRIBUTEUR => PrixOrigine::DISTRIBUTEUR,
        };
    }
}
