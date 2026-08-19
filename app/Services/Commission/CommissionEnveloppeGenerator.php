<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionGenerationDeclenchePar;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionUniteCalcul;
use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppeLigne;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Services\CommissionCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Moteur générique de génération d'enveloppes — nouveau schéma parallèle
 * (cf. conception cible §B). Point d'entrée à appeler UNIQUEMENT après le
 * commit de l'opération métier qui déclenche la génération (encaissement,
 * réception...), jamais à l'intérieur de sa transaction : cette méthode ouvre
 * sa PROPRE transaction, isolée, et n'échoue jamais de façon à faire annuler
 * l'opération appelante (décision AMOA #2, round 2 — cf. §0.1.2 de la
 * conception cible).
 *
 * PONT PHASE 1 : `genererPourCommandeVente()` reproduit exactement la formule
 * de l'ancien CommissionCalculator (marge de l'opération, répartie par
 * pourcentage), mais publiée sur le nouveau schéma générique — enveloppe
 * propriétaire directe séparée de l'enveloppe livraison collective (au lieu
 * du pot unique de l'ancien moteur). Volontairement PAS encore branché sur le
 * déclencheur réel (CommissionTriggerService / EncaissementVente) : le
 * nouveau moteur reste inerte pour toutes les organisations tant qu'aucun
 * `commission_processus` n'a été seedé pour elles (cf. Console\Commands\
 * SeedCommissionsV2Fondations).
 */
class CommissionEnveloppeGenerator
{
    public static function genererPourCommandeVente(
        CommandeVente $commande,
        CommissionGenerationDeclenchePar $declenchePar = CommissionGenerationDeclenchePar::SYSTEME,
        ?string $declencheurUserId = null,
    ): void {
        $processus = CommissionProcessus::query()
            ->where('organization_id', $commande->organization_id)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->where('statut', CommissionActivationStatut::ACTIF->value)
            ->first();

        if (! $processus) {
            // Nouveau moteur non activé pour cette organisation : inerte, comme
            // n'importe quelle organisation qui n'a jamais rien seedé.
            return;
        }

        $dejaGenere = CommissionEnveloppe::query()
            ->where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->exists();
        if ($dejaGenere) {
            return;
        }

        try {
            DB::transaction(function () use ($commande, $processus) {
                self::genererDansTransaction($commande, $processus);
            });

            CommissionGenerationAttempt::create([
                'organization_id' => $commande->organization_id,
                'source_type' => CommandeVente::class,
                'source_id' => $commande->id,
                'processus_id' => $processus->id,
                'statut' => CommissionGenerationStatut::SUCCES->value,
                'declenchee_par' => $declenchePar->value,
                'created_by' => $declencheurUserId,
            ]);
        } catch (InvalidArgumentException $e) {
            Log::warning('Génération commission v2 en erreur : '.$e->getMessage(), [
                'commande_id' => $commande->id,
            ]);

            CommissionGenerationAttempt::create([
                'organization_id' => $commande->organization_id,
                'source_type' => CommandeVente::class,
                'source_id' => $commande->id,
                'processus_id' => $processus->id,
                'statut' => CommissionGenerationStatut::ERREUR->value,
                'motif_erreur' => $e->getMessage(),
                'detail_erreur' => ['erreurs' => [$e->getMessage()]],
                'declenchee_par' => $declenchePar->value,
                'created_by' => $declencheurUserId,
            ]);

            // Volontairement pas de rethrow : un échec de génération n'est jamais
            // une erreur de l'opération commerciale qui l'a déclenchée. L'opération
            // reste "à régulariser", jamais rollbackée.
        }
    }

    private static function genererDansTransaction(CommandeVente $commande, CommissionProcessus $processus): void
    {
        $commande->loadMissing(['lignes', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire']);

        $vehicule = $commande->vehicule;
        if (! $vehicule) {
            throw new InvalidArgumentException("La commande {$commande->id} ne possède pas de véhicule lié.");
        }
        if (! $vehicule->equipe) {
            throw new InvalidArgumentException(
                "Le véhicule « {$vehicule->nom_vehicule} » n'a pas d'équipe de livraison assignée."
            );
        }

        $equipe = $vehicule->equipe;
        $tauxProprietaire = (float) $equipe->taux_commission_proprietaire;

        // Même garde-fou que l'ancien moteur — reproduit ici car le pont Phase 1 lit
        // directement equipe_livraison/equipe_livreurs plutôt qu'un commission_regle
        // organisation-wide (ce taux est intrinsèquement propre à ce véhicule).
        CommissionCalculator::validateTauxTotal($equipe, $tauxProprietaire);

        $lignes = $commande->lignes;
        $prixVente = $lignes->sum(fn ($l) => (float) $l->quantite_chargee * (float) $l->prix_vente_snapshot);
        $prixUsine = $lignes->sum(fn ($l) => (float) $l->quantite_chargee * (float) $l->prix_usine_snapshot);
        $margeOperation = max(0.0, $prixVente - $prixUsine);

        $earnedAt = Carbon::today();

        $regleProprietaire = self::regleBridge($commande->organization_id, $processus->id, CommissionCibleType::CODE_PROPRIETAIRE);
        $regleLivraison = self::regleBridge($commande->organization_id, $processus->id, CommissionCibleType::CODE_EQUIPE_LIVRAISON);

        // ── Enveloppe propriétaire (DIRECTE) ────────────────────────────────
        $montantProprietaire = round($margeOperation * $tauxProprietaire / 100, 2);

        $enveloppeProprietaire = CommissionEnveloppe::create([
            'organization_id' => $commande->organization_id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => $vehicule->proprietaire_id,
            'montant_total' => $montantProprietaire,
            'earned_at' => $earnedAt,
            'statut' => StatutCommission::CREEE->value,
        ]);

        self::creerLignesTracabilite($enveloppeProprietaire, $lignes, $regleProprietaire, $tauxProprietaire / 100);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppeProprietaire->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $vehicule->proprietaire_id,
            'taux_repartition_snapshot' => null,
            'montant_brut' => $montantProprietaire,
            'montant_net' => $montantProprietaire,
            'statut' => StatutCommission::CREEE->value,
            'origine' => OrigineCommissionPart::THEORIQUE->value,
        ]);

        // ── Enveloppe livraison (A_REPARTIR) ────────────────────────────────
        $groupe = CommissionGroupeSyncService::syncEquipeLivraisonVehicule($vehicule);
        $membresActifs = $groupe->membresActifsA($earnedAt);

        $montantLivraison = round($margeOperation * (100 - $tauxProprietaire) / 100, 2);

        $enveloppeLivraison = CommissionEnveloppe::create([
            'organization_id' => $commande->organization_id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => $groupe->id,
            'montant_total' => $montantLivraison,
            'earned_at' => $earnedAt,
            'statut' => StatutCommission::CREEE->value,
        ]);

        self::creerLignesTracabilite($enveloppeLivraison, $lignes, $regleLivraison, (100 - $tauxProprietaire) / 100);

        $parts = CommissionRepartitionEngine::repartir($montantLivraison, $membresActifs);
        foreach ($parts as $p) {
            CommissionEnveloppePart::create([
                'enveloppe_id' => $enveloppeLivraison->id,
                'beneficiaire_type' => $p['beneficiaire_type'],
                'beneficiaire_id' => $p['beneficiaire_id'],
                'taux_repartition_snapshot' => $p['part_pourcentage'],
                'montant_brut' => $p['montant'],
                'montant_net' => $p['montant'],
                'statut' => StatutCommission::CREEE->value,
                'origine' => OrigineCommissionPart::THEORIQUE->value,
            ]);
        }
    }

    private static function regleBridge(string $organizationId, string $processusId, string $cibleType): ?CommissionRegle
    {
        return CommissionRegle::query()
            ->where('organization_id', $organizationId)
            ->where('processus_id', $processusId)
            ->where('cible_type', $cibleType)
            ->where('unite_calcul', CommissionUniteCalcul::MARGE_OPERATION->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->first();
    }

    /**
     * @param  Collection<int, CommandeVenteLigne>  $lignes
     */
    private static function creerLignesTracabilite(
        CommissionEnveloppe $enveloppe,
        Collection $lignes,
        ?CommissionRegle $regle,
        float $partCible,
    ): void {
        foreach ($lignes as $ligne) {
            $margeLigne = (float) $ligne->quantite_chargee
                * ((float) $ligne->prix_vente_snapshot - (float) $ligne->prix_usine_snapshot);

            CommissionEnveloppeLigne::create([
                'enveloppe_id' => $enveloppe->id,
                'source_ligne_type' => CommandeVenteLigne::class,
                'source_ligne_id' => $ligne->id,
                'variante_id' => $ligne->variante_id,
                'categorie_id_snapshot' => null,
                'commission_regle_id' => $regle?->id,
                'quantite' => $ligne->quantite_chargee,
                'unite_calcul_snapshot' => CommissionUniteCalcul::MARGE_OPERATION->value,
                'montant_ligne' => round($margeLigne * $partCible, 2),
            ]);
        }
    }
}
