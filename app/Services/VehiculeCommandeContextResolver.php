<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Enums\ModeTarification;
use App\Models\Client;
use App\Models\Vehicule;

/**
 * Point d'entrée unique pour dériver, à partir du véhicule et/ou du client d'une commande, le
 * mode de tarification ET l'éligibilité aux commissions — deux notions indépendantes (cf.
 * VehiculeCommandeContext), toutes deux figées en snapshot sur la commande à sa
 * création/modification (CommandeVenteController, PdvCheckoutService) pour ne jamais
 * recalculer rétroactivement une commande déjà passée si le véhicule ou le client change ensuite.
 *
 * Règle métier (cf. analyse du modèle véhicules/partenaires/commissions) :
 *  - un véhicule de flotte gérée (toute ligne présente dans `vehicules`, quelle que soit sa
 *    catégorie INTERNE/PARTENAIRE) facture toujours au prix de vente plein, et n'est éligible
 *    aux commissions que s'il est autorisé `livraison_vente` ;
 *  - une vente sans véhicule de flotte pour un client EXTERNE facture à prix usine, jamais
 *    de commission (aucun véhicule de flotte impliqué) ;
 *  - une vente sans véhicule de flotte pour un client Revendeur ou Distributeur facture au prix
 *    de vente plein (hors produit fabricable, cf. PrixVenteNatureResolver), comportement
 *    historique inchangé.
 *
 * Centralise ce qui était auparavant dupliqué à l'identique entre
 * CommandeVenteController::resolveModeTarification() et
 * PdvCheckoutService::resolveModeTarification().
 */
class VehiculeCommandeContextResolver
{
    public static function resolve(?string $vehiculeId, ?string $clientId = null): VehiculeCommandeContext
    {
        if ($vehiculeId) {
            $vehicule = Vehicule::query()
                ->select(['id', 'livraison_vente', 'type_vehicule_id'])
                ->with('typeVehicule:id,categorie_tarifaire')
                ->find($vehiculeId);

            return new VehiculeCommandeContext(
                ModeTarification::PRIX_VENTE,
                (bool) ($vehicule?->livraison_vente ?? true),
                $vehicule?->typeVehicule?->categorie_tarifaire,
            );
        }

        // Sans véhicule de flotte : CommissionGenerator exige de toute façon un véhicule, donc
        // commissionEligible est toujours false ici — seule la tarification varie selon le
        // client. Pas de catégorie tarifaire non plus : aucun véhicule de flotte impliqué,
        // PrixUsineResolver retombe sur le tarif "autres véhicules" par défaut.
        $client = $clientId ? Client::query()->select(['id', 'type'])->find($clientId) : null;
        $modeTarification = $client?->type === ClientType::EXTERNE
            ? ModeTarification::PRIX_USINE
            : ModeTarification::PRIX_VENTE;

        return new VehiculeCommandeContext($modeTarification, false);
    }
}
