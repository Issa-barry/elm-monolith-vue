<?php

namespace App\Services\Commission;

use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Models\CommissionRegle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

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
        foreach (self::ordreDeRecherche($varianteId, $produitId, $categorieId, $typeVehiculeId) as [$scopeType, $scopeId, $type]) {
            $regle = self::chercher($organizationId, $processusId, $cibleType, $scopeType, $scopeId, $date, $type);
            if ($regle) {
                return $regle;
            }
        }

        return null;
    }

    /**
     * Ordre de recherche de resolve() : à chaque niveau de portée (variante, produit, catégorie,
     * globale), l'exception du type de véhicule exact puis le barème standard.
     *
     * @return list<array{0: CommissionScopeType, 1: ?string, 2: ?string}> [portée, scope_id, type_vehicule_id]
     */
    public static function ordreDeRecherche(?string $varianteId, ?string $produitId, ?string $categorieId, ?string $typeVehiculeId): array
    {
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

        $ordre = [];
        foreach ($candidats as [$scopeType, $scopeId]) {
            if ($typeVehiculeId !== null) {
                $ordre[] = [$scopeType, $scopeId, $typeVehiculeId];
            }
            $ordre[] = [$scopeType, $scopeId, null];
        }

        return $ordre;
    }

    /**
     * Règles d'une cible applicables à une date, de la plus récemment entrée en vigueur à la plus
     * ancienne — filtres communs à chaque recherche de resolve() (et au chargement groupé de
     * ReglesCommissionPrechargees), avant le processus, la portée et le type de véhicule.
     *
     * @return Builder<CommissionRegle>
     */
    public static function requeteApplicables(string $organizationId, string $cibleType, CarbonInterface $date): Builder
    {
        return CommissionRegle::query()
            ->where('organization_id', $organizationId)
            ->where('cible_type', $cibleType)
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
        $query = self::requeteApplicables($organizationId, $cibleType, $date)
            ->where('processus_id', $processusId)
            ->where('scope_type', $scopeType->value);

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
