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
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString()));

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
