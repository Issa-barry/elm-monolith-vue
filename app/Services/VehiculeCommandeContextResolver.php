<?php

namespace App\Services;

use App\Enums\ModeTarification;
use App\Models\Vehicule;

/**
 * Point d'entrée unique pour dériver, à partir d'un véhicule éventuellement
 * assigné à une commande, le mode de tarification ET l'éligibilité aux
 * commissions — deux notions indépendantes (cf. VehiculeCommandeContext),
 * toutes deux figées en snapshot sur la commande à sa création/modification
 * (CommandeVenteController, PdvCheckoutService) pour ne jamais recalculer
 * rétroactivement une commande déjà passée si le véhicule change ensuite.
 *
 * Centralise ce qui était auparavant dupliqué à l'identique entre
 * CommandeVenteController::resolveModeTarification() et
 * PdvCheckoutService::resolveModeTarification().
 */
class VehiculeCommandeContextResolver
{
    public static function resolve(?string $vehiculeId): VehiculeCommandeContext
    {
        if (! $vehiculeId) {
            // Vente directe client (sans véhicule) : plein prix de vente,
            // aucune commission de toute façon (CommissionGenerator exige un
            // véhicule) — la valeur ici n'a donc pas d'effet pratique.
            return new VehiculeCommandeContext(ModeTarification::PRIX_VENTE, true);
        }

        $vehicule = Vehicule::query()
            ->select(['id', 'pris_en_charge_par_usine', 'commission_eligible'])
            ->find($vehiculeId);

        return new VehiculeCommandeContext(
            ModeTarification::fromPrisEnChargeParUsine($vehicule?->pris_en_charge_par_usine ?? true),
            (bool) ($vehicule?->commission_eligible ?? true),
        );
    }
}
