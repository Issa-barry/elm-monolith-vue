<?php

namespace App\Services\Commission;

use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\Produit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Source unique de la règle "le partage Livreur par catégorie couvre-t-il cette opération ?" —
 * réutilisée par des appelants qui doivent absolument s'accorder sur la même réponse :
 * CommissionEnveloppeGenerator (génération réelle, différée au déclencheur configuré par
 * l'organisation), EquipeLivraisonController::validatePartagesCategorie (cohérence à la
 * sauvegarde de l'équipe) et les garde-fous préventifs à la création d'une opération
 * (CommandeVenteController, TransfertLogistiqueController) — ces derniers réduisent le risque
 * qu'une commande/un transfert paraisse "payé(e)" mais reste bloqué(e) à "à régulariser" faute de
 * partage configuré (cf. incident CMD-300826-007, 30/08/2026), sans jamais remplacer le filet de
 * sécurité de la génération elle-même : la configuration peut encore changer entre la création
 * (contrôle ici) et la génération (différée).
 *
 * Barème Livreur résolu au niveau CATÉGORIE uniquement (jamais variante/produit) : le partage
 * entre livreurs n'a lui-même jamais été défini plus finement qu'une catégorie (cf.
 * EquipeLivraisonPartageCategorie).
 */
class CommissionPartageLivraisonCategorieChecker
{
    /**
     * Enveloppe PAR_UNITE_VENDUE à distribuer entre livreurs pour cette catégorie/processus — 0
     * si aucune règle active ne la couvre (décision AMOA #4 : absence de règle = 0, jamais une
     * erreur). $processusId peut être null (processus jamais configuré pour l'organisation) :
     * revient alors toujours à 0, aucune CommissionRegle ne pouvant référencer un processus
     * inexistant.
     */
    public static function resoudreEnveloppe(
        string $organizationId,
        ?string $processusId,
        string $categorieId,
        ?string $typeVehiculeId,
        CarbonInterface $date,
    ): int {
        if ($processusId === null) {
            return 0;
        }

        $regle = CommissionRegleResolver::resolve(
            $organizationId,
            $processusId,
            CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            null,
            null,
            $categorieId,
            $date,
            $typeVehiculeId,
        );

        return (int) round((float) ($regle?->montant ?? 0));
    }

    /**
     * Lignes de partage actives (effective_from/effective_to) pour cette équipe/catégorie/
     * processus à la date donnée — jamais la config courante si $date est dans le passé (relance
     * d'une génération historique, cf. EquipeLivraisonController::syncPartagesCategorie).
     *
     * @return Collection<int, EquipeLivraisonPartageCategorie>
     */
    public static function partagesActifs(
        string $processusId,
        string $equipeId,
        string $categorieId,
        CarbonInterface $date,
    ): Collection {
        return EquipeLivraisonPartageCategorie::where('processus_id', $processusId)
            ->where('equipe_id', $equipeId)
            ->where('categorie_id', $categorieId)
            ->actifA($date)
            ->get();
    }

    /**
     * Catégories, parmi $categorieIds, pour lesquelles l'équipe n'a AUCUN partage actif alors que
     * l'enveloppe à distribuer est positive — exactement les catégories qui feraient échouer
     * CommissionEnveloppeGenerator ("à régulariser") si une opération de cette équipe pour cette
     * catégorie/ce processus était générée à $date. Une enveloppe à 0 (aucune règle, ou règle à 0)
     * n'exige jamais de partage : "rien à distribuer" est une valeur métier valide, jamais une
     * configuration manquante.
     *
     * @param  iterable<string>  $categorieIds
     * @return Collection<int, Categorie>
     */
    public static function categoriesManquantes(
        string $organizationId,
        string $equipeId,
        string $processusCode,
        ?string $typeVehiculeId,
        iterable $categorieIds,
        CarbonInterface $date,
    ): Collection {
        $categorieIds = collect($categorieIds)->filter()->unique()->values();
        if ($categorieIds->isEmpty()) {
            return collect();
        }

        $processus = CommissionProcessus::where('organization_id', $organizationId)
            ->where('code', $processusCode)
            ->first();

        $idsManquants = $categorieIds->filter(function (string $categorieId) use ($organizationId, $equipeId, $processus, $typeVehiculeId, $date) {
            if (! $processus) {
                // Processus jamais configuré pour l'organisation : aucune CommissionRegle ne peut
                // y référer, l'enveloppe est donc toujours 0 — rien à exiger.
                return false;
            }

            $enveloppe = self::resoudreEnveloppe($organizationId, $processus->id, $categorieId, $typeVehiculeId, $date);
            if ($enveloppe <= 0) {
                return false;
            }

            return self::partagesActifs($processus->id, $equipeId, $categorieId, $date)->isEmpty();
        });

        if ($idsManquants->isEmpty()) {
            return collect();
        }

        return Categorie::whereIn('id', $idsManquants)->get();
    }

    /**
     * Résout les catégories distinctes concernées par des lignes brutes de requête
     * ([['produit_id' => ..., ...], ...]) — un produit sans catégorie n'est jamais concerné par
     * un contrôle qui, par définition, se déclenche par catégorie (même règle que
     * VehiculeCapaciteService::verifier).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, string>
     */
    public static function categorieIdsDepuisLignes(array $lignes, string $produitKey = 'produit_id'): array
    {
        $produitIds = collect($lignes)->pluck($produitKey)->filter()->unique()->values()->all();

        return Produit::whereIn('id', $produitIds)
            ->whereNotNull('categorie_id')
            ->pluck('categorie_id')
            ->unique()
            ->values()
            ->all();
    }
}
