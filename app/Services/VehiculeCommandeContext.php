<?php

namespace App\Services;

use App\Enums\ModeTarification;

/**
 * Contexte figé (snapshot) résolu depuis le véhicule et/ou le client d'une commande au moment
 * de sa création — voir VehiculeCommandeContextResolver. Regroupe deux notions volontairement
 * indépendantes :
 *  - modeTarification : quel prix sert de base au montant facturé (véhicule de flotte → prix
 *    de vente ; client PARTENAIRE sans véhicule → prix usine, cf. ModeTarification).
 *  - commissionEligible : la commande génère-t-elle une commission (dérivé de
 *    Vehicule::livraison_vente, jamais vrai sans véhicule de flotte — cf. CommissionGenerator).
 */
final readonly class VehiculeCommandeContext
{
    public function __construct(
        public ModeTarification $modeTarification,
        public bool $commissionEligible,
    ) {}
}
