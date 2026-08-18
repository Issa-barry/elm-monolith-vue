<?php

namespace App\Services;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\ProduitVariante;

/**
 * Point d'entrée unique pour déterminer le prix usine applicable à une ligne de commande —
 * jamais de comparaison directe sur `$variante->prix_usine` dans un contrôleur/service dès que
 * la catégorie tarifaire du véhicule de livraison entre en jeu (cf. analyse du modèle de
 * tarification tricycle/autres véhicules). Un seul point de vérité, réutilisé partout où ce
 * prix doit être calculé (commandes, PDV) pour ne jamais laisser deux implémentations diverger.
 *
 * Sans catégorie connue (véhicule de flotte non classé, ou aucun véhicule impliqué — cf.
 * VehiculeCommandeContextResolver), retombe sur le tarif "autres véhicules" (`prix_usine`) —
 * comportement historique inchangé pour toute organisation qui n'a pas encore classé ses types
 * de véhicules.
 */
class PrixUsineResolver
{
    public static function resolve(ProduitVariante $variante, ?CategorieTarifaireVehicule $categorieTarifaire): int
    {
        if ($categorieTarifaire === CategorieTarifaireVehicule::TRICYCLE) {
            return (int) ($variante->prix_usine_tricycle ?? $variante->prix_usine ?? 0);
        }

        return (int) ($variante->prix_usine ?? 0);
    }
}
