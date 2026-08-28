<?php

namespace App\Services;

use App\Enums\CategorieTarifaireVehicule;
use App\Enums\ModeTarification;

/**
 * Contexte figé (snapshot) résolu depuis le véhicule et/ou le client d'une commande au moment
 * de sa création — voir VehiculeCommandeContextResolver. Regroupe trois notions volontairement
 * indépendantes :
 *  - modeTarification : quel prix sert de base au montant facturé (véhicule de flotte → prix
 *    de vente ; client EXTERNE sans véhicule → prix usine, cf. ModeTarification).
 *  - commissionEligible : la commande génère-t-elle une commission (dérivé de
 *    Vehicule::livraison_vente, jamais vrai sans véhicule de flotte — cf. CommissionGenerator).
 *  - categorieTarifaireVehicule : catégorie tarifaire du véhicule de flotte livrant la commande
 *    (null si aucun véhicule de flotte, ou véhicule dont le type n'est pas classé) — consommée
 *    par PrixUsineResolver pour choisir entre prix_usine et prix_usine_tricycle sur chaque ligne.
 */
final readonly class VehiculeCommandeContext
{
    public function __construct(
        public ModeTarification $modeTarification,
        public bool $commissionEligible,
        public ?CategorieTarifaireVehicule $categorieTarifaireVehicule = null,
    ) {}
}
