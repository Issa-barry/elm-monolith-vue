<?php

namespace App\Services\Commission;

use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Models\CommissionRegle;
use Carbon\CarbonInterface;

/**
 * Résolution stricte, sans héritage (décision AMOA #3) :
 * Variante exacte -> Produit -> Catégorie exacte -> Règle globale -> Aucune règle.
 * Absence de règle = null (pas d'exception) : c'est à l'appelant de traduire
 * "aucune règle" en "commission = 0 pour cette cible" (décision AMOA #4).
 *
 * Type de véhicule : axe secondaire imbriqué dans chaque niveau de portée
 * (jamais un niveau de portée à part) — à chaque niveau, une exception pour le
 * type de véhicule exact prévaut sur le barème standard (type_vehicule_id NULL)
 * du même niveau, avant de retomber sur le niveau de portée suivant.
 *
 * Résolution par DATE (R1, 24/09/2026) : une règle depuis remplacée (statut `remplacee`) reste
 * applicable sur sa propre fenêtre [effective_from, effective_to] — sans quoi toute régénération
 * à une date passée (retour partiel, relance d'une génération partielle, réception logistique
 * validée après coup) ne retrouvait plus le barème réellement en vigueur ce jour-là et générait
 * silencieusement 0. Seul un brouillon (jamais publié) n'est jamais applicable.
 */
class CommissionRegleResolver
{
    public static function resolve(
        string $organizationId,
        string $processusId,
        string $cibleType,
        ?string $varianteId,
        ?string $produitId,
        ?string $categorieId,
        CarbonInterface $date,
        ?string $typeVehiculeId = null,
    ): ?CommissionRegle {
        $candidats = [];

        if ($varianteId !== null) {
            $candidats[] = [CommissionScopeType::VARIANTE, $varianteId];
        }
        if ($produitId !== null) {
            $candidats[] = [CommissionScopeType::PRODUIT, $produitId];
        }
        if ($categorieId !== null) {
            $candidats[] = [CommissionScopeType::CATEGORIE, $categorieId];
        }
        $candidats[] = [CommissionScopeType::GLOBAL, null];

        foreach ($candidats as [$scopeType, $scopeId]) {
            if ($typeVehiculeId !== null) {
                $regle = self::chercher($organizationId, $processusId, $cibleType, $scopeType, $scopeId, $date, $typeVehiculeId);
                if ($regle) {
                    return $regle;
                }
            }

            $regle = self::chercher($organizationId, $processusId, $cibleType, $scopeType, $scopeId, $date, null);
            if ($regle) {
                return $regle;
            }
        }

        return null;
    }

    private static function chercher(
        string $organizationId,
        string $processusId,
        string $cibleType,
        CommissionScopeType $scopeType,
        ?string $scopeId,
        CarbonInterface $date,
        ?string $typeVehiculeId,
    ): ?CommissionRegle {
        $query = CommissionRegle::query()
            ->where('organization_id', $organizationId)
            ->where('processus_id', $processusId)
            ->where('cible_type', $cibleType)
            ->where('scope_type', $scopeType->value)
            ->whereIn('statut', [CommissionRegleStatut::ACTIVE->value, CommissionRegleStatut::REMPLACEE->value])
            // whereDate (comme EquipeLivraisonPartageCategorie::scopeActifA()) : comparaison par
            // JOUR quel que soit le moteur — une comparaison de chaînes excluait sous SQLite une
            // règle entrant en vigueur le jour même (valeur stockée « AAAA-MM-JJ 00:00:00 »).
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            // Déterminisme si deux versions se chevauchaient (donnée historique incohérente) : la
            // plus récemment entrée en vigueur l'emporte, jamais un ordre SQL arbitraire.
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');

        if ($scopeId === null) {
            $query->whereNull('scope_id');
        } else {
            $query->where('scope_id', $scopeId);
        }

        if ($typeVehiculeId === null) {
            $query->whereNull('type_vehicule_id');
        } else {
            $query->where('type_vehicule_id', $typeVehiculeId);
        }

        return $query->first();
    }
}
