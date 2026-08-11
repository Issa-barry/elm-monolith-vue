<?php

namespace App\Services;

use App\Enums\ModeTarification;

/**
 * Contexte figé (snapshot) résolu depuis le véhicule d'une commande au moment
 * de sa création — voir VehiculeCommandeContextResolver. Regroupe deux
 * notions volontairement indépendantes :
 *  - modeTarification : quel prix sert de base au montant facturé (dérivé de
 *    Vehicule::pris_en_charge_par_usine, cf. ModeTarification).
 *  - commissionEligible : la commande génère-t-elle une commission (dérivé de
 *    Vehicule::commission_eligible, cf. CommissionGenerator).
 */
final readonly class VehiculeCommandeContext
{
    public function __construct(
        public ModeTarification $modeTarification,
        public bool $commissionEligible,
    ) {}
}
