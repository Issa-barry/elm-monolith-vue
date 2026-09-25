<?php

namespace App\Services\Commission;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\TypeVehicule;
use Illuminate\Support\Carbon;

/**
 * Application d'une configuration complète de Paramètres → Commissions (barèmes fixes
 * PAR_UNITE_VENDUE par catégorie/cible, exceptions par type de véhicule) — source UNIQUE partagée
 * par l'enregistrement direct (Settings\CommissionRegleController::storeConfiguration(), quand
 * aucune équipe n'est impactée) et la publication d'un brouillon de barème
 * (ReconfigurationPartagesService::publier()), jamais deux implémentations.
 *
 * Une "modification" n'écrase jamais une règle existante : elle clôture la version active
 * (`effective_to` = veille) et crée une nouvelle ligne (`remplace_regle_id`) effective ce jour —
 * cf. décision AMOA "aucune modification rétroactive".
 */
class CommissionBaremeConfigurationService
{
    /**
     * Fait converger les règles actives du processus vers $lignes. Une catégorie absente de
     * $lignes voit toutes ses règles closes (retrait complet) ; les anciennes règles globales
     * legacy sont closes pour éviter tout repli silencieux. À appeler dans une transaction.
     *
     * @param  array<int, array{categorie_id: string, beneficiaires: list<string>, consultant_id?: ?string, montants_standard: array<string, int|float|string>, exceptions?: list<array{type_vehicule_id: string, montants: array<string, int|float|string>}>}>  $lignes
     */
    public static function appliquer(string $orgId, string $processusCode, array $lignes, ?string $userId): CommissionProcessus
    {
        $today = Carbon::today()->toDateString();
        $hier = Carbon::parse($today)->subDay()->toDateString();

        $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, $processusCode);
        $categorieIds = collect($lignes)->pluck('categorie_id')->all();

        CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->where(function ($query) use ($categorieIds): void {
                $query->where('scope_type', 'global')
                    ->orWhere(function ($categoryQuery) use ($categorieIds): void {
                        $categoryQuery->where('scope_type', 'categorie')
                            ->whereNotIn('scope_id', $categorieIds);
                    });
            })
            ->update([
                'effective_to' => $hier,
                'statut' => CommissionRegleStatut::REMPLACEE->value,
            ]);

        foreach ($lignes as $ligne) {
            self::enregistrerConfigurationCategorie($orgId, $processus, $ligne, $today, $userId);
        }

        return $processus;
    }

    /**
     * Barème Livreur (GNF/pack) que $lignes appliquerait à (catégorie, type de véhicule) — même
     * règle que CommissionRegleResolver une fois la configuration appliquée : exception du type
     * si elle existe, sinon montant général ; 0 si le Livreur n'est pas bénéficiaire de la
     * catégorie ou si la catégorie est absente (règles globales closes par appliquer()).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    public static function baremeLivreurDepuisLignes(array $lignes, string $categorieId, ?string $typeVehiculeId): int
    {
        $ligne = collect($lignes)->firstWhere('categorie_id', $categorieId);
        $cible = CommissionCibleType::CODE_EQUIPE_LIVRAISON;

        if (! $ligne || ! in_array($cible, $ligne['beneficiaires'] ?? [], true)) {
            return 0;
        }

        if ($typeVehiculeId !== null) {
            foreach ($ligne['exceptions'] ?? [] as $exception) {
                if (($exception['type_vehicule_id'] ?? null) === $typeVehiculeId && array_key_exists($cible, $exception['montants'] ?? [])) {
                    return (int) $exception['montants'][$cible];
                }
            }
        }

        return (int) ($ligne['montants_standard'][$cible] ?? 0);
    }

    /**
     * Empreinte des règles PAR_UNITE_VENDUE actives du processus (identifiants + montants) —
     * change dès qu'une règle est créée, remplacée ou retirée par un autre chemin.
     */
    public static function signatureReglesActives(string $orgId, string $processusId): string
    {
        $empreinte = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processusId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->orderBy('id')
            ->get(['id', 'montant', 'consultant_id'])
            ->map(fn (CommissionRegle $r) => $r->id.':'.(int) $r->montant.':'.$r->consultant_id)
            ->implode('|');

        return hash('sha256', $empreinte);
    }

    public static function libelleAuto(string $cibleType, string $scopeType, ?string $scopeId, ?string $typeVehiculeId = null): string
    {
        $cibleLabel = match ($cibleType) {
            CommissionCibleType::CODE_PROPRIETAIRE => 'Propriétaire',
            CommissionCibleType::CODE_SITE => 'Site',
            CommissionCibleType::CODE_CONSULTANT => 'Consultant',
            default => 'Livreur',
        };
        $scopeLabel = $scopeType === 'global'
            ? 'toutes catégories'
            : (Categorie::find($scopeId)?->nom ?? 'catégorie');

        $libelle = "{$cibleLabel} — {$scopeLabel}";

        if ($typeVehiculeId) {
            $vehiculeNom = TypeVehicule::find($typeVehiculeId)?->nom ?? 'véhicule';
            $libelle .= " ({$vehiculeNom})";
        }

        return $libelle;
    }

    /**
     * Traduit une ligne en un ensemble désiré de règles (cible_type, type_vehicule_id) et fait
     * converger l'état actif de la catégorie vers cet ensemble : ferme ce qui n'est plus désiré,
     * verse (no-op si inchangé, sinon clôture + nouvelle version) ce qui l'est. Les règles sans
     * type constituent le barème général ; les règles typées sont ses exceptions.
     */
    private static function enregistrerConfigurationCategorie(
        string $orgId,
        CommissionProcessus $processus,
        array $ligne,
        string $today,
        ?string $userId,
    ): void {
        $categorieId = $ligne['categorie_id'];
        $beneficiaires = $ligne['beneficiaires'];
        $consultantId = in_array(CommissionCibleType::CODE_CONSULTANT, $beneficiaires, true)
            ? ($ligne['consultant_id'] ?? null)
            : null;

        $desired = [];
        foreach ($beneficiaires as $cibleType) {
            $desired[$cibleType]['std'] = (int) $ligne['montants_standard'][$cibleType];
        }
        foreach ($ligne['exceptions'] ?? [] as $tarifVehicule) {
            $typeVehiculeId = $tarifVehicule['type_vehicule_id'];
            foreach ($tarifVehicule['montants'] as $cibleType => $montant) {
                $desired[$cibleType][$typeVehiculeId] = (int) $montant;
            }
        }

        $reglesActuelles = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('scope_type', 'categorie')
            ->where('scope_id', $categorieId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->get();

        $idsAFermer = $reglesActuelles
            ->reject(fn (CommissionRegle $r) => isset($desired[$r->cible_type][$r->type_vehicule_id ?? 'std']))
            ->pluck('id');

        if ($idsAFermer->isNotEmpty()) {
            CommissionRegle::whereIn('id', $idsAFermer)->update([
                'effective_to' => Carbon::parse($today)->subDay()->toDateString(),
                'statut' => CommissionRegleStatut::REMPLACEE->value,
            ]);
        }

        foreach ($desired as $cibleType => $parVehicule) {
            foreach ($parVehicule as $cle => $montant) {
                self::enregistrerRegleCategorie(
                    $orgId,
                    $processus,
                    $categorieId,
                    $cibleType,
                    $montant,
                    $today,
                    $cibleType === CommissionCibleType::CODE_CONSULTANT ? $consultantId : null,
                    $cle === 'std' ? null : $cle,
                    $userId,
                );
            }
        }
    }

    private static function enregistrerRegleCategorie(
        string $orgId,
        CommissionProcessus $processus,
        string $categorieId,
        string $cibleType,
        int $montant,
        string $effectiveFrom,
        ?string $consultantId,
        ?string $typeVehiculeId,
        ?string $userId,
    ): void {
        $ancienne = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('cible_type', $cibleType)
            ->where('scope_type', 'categorie')
            ->where('scope_id', $categorieId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->when(
                $typeVehiculeId === null,
                fn ($q) => $q->whereNull('type_vehicule_id'),
                fn ($q) => $q->where('type_vehicule_id', $typeVehiculeId),
            )
            ->first();

        if ($ancienne
            && (int) $ancienne->montant === $montant
            && $ancienne->consultant_id === $consultantId) {
            return;
        }

        $mode = in_array($cibleType, [
            CommissionCibleType::CODE_PROPRIETAIRE,
            CommissionCibleType::CODE_SITE,
            CommissionCibleType::CODE_CONSULTANT,
        ], true) ? CommissionMode::DIRECT : CommissionMode::A_REPARTIR;

        CommissionRegle::create([
            'organization_id' => $orgId,
            'processus_id' => $processus->id,
            'libelle' => self::libelleAuto($cibleType, 'categorie', $categorieId, $typeVehiculeId),
            'scope_type' => 'categorie',
            'scope_id' => $categorieId,
            'type_vehicule_id' => $typeVehiculeId,
            'cible_type' => $cibleType,
            'mode' => $mode->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'consultant_id' => $consultantId,
            'effective_from' => $effectiveFrom,
            'remplace_regle_id' => $ancienne?->id,
            'statut' => CommissionRegleStatut::ACTIVE->value,
            'created_by' => $userId,
        ]);

        if ($ancienne) {
            $ancienne->update([
                'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                'statut' => CommissionRegleStatut::REMPLACEE->value,
            ]);
        }
    }
}
