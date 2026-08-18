<?php

namespace App\Services;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\ProduitVariante;
use Illuminate\Validation\ValidationException;

/**
 * Point d'entrée unique pour déterminer le prix usine applicable à une ligne de commande —
 * jamais de comparaison directe sur `$variante->prix_usine` dans un contrôleur/service dès que
 * la catégorie tarifaire du véhicule de livraison entre en jeu (cf. analyse du modèle de
 * tarification tricycle/autres véhicules). Un seul point de vérité, réutilisé partout où ce
 * prix doit être calculé (commandes, PDV) pour ne jamais laisser deux implémentations diverger.
 *
 * Décision métier : les deux tarifs (autres véhicules / tricycle) sont deux décisions
 * tarifaires distinctes, jamais l'un déduit de l'autre — ProduitService::validerPrixSelonType()
 * garantit déjà que les deux sont renseignés dès qu'un type utilise le prix usine
 * (ProduitType::requiredPrices()), donc le cas TRICYCLE sans tarif dédié ne devrait
 * fonctionnellement jamais se produire pour une variante créée/éditée après ce garde-fou.
 * S'il se produit malgré tout (variante historique jamais migrée, incohérence de données),
 * on refuse explicitement plutôt que d'appliquer silencieusement un autre prix — voir la
 * migration de rattrapage `2026_08_18_000003_backfill_prix_usine_tricycle` pour les données
 * déjà en place au moment du déploiement.
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
            if ($variante->prix_usine_tricycle === null) {
                throw ValidationException::withMessages([
                    'lignes' => "Le prix usine — Tricycle n'est pas renseigné pour « {$variante->produit?->nom} ».",
                ]);
            }

            return (int) $variante->prix_usine_tricycle;
        }

        return (int) ($variante->prix_usine ?? 0);
    }
}
