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
use App\Models\CommissionGroupeMembre;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Services\CommissionCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Moteur générique de génération d'enveloppes — nouveau schéma parallèle
 * (cf. conception cible §B). Chaque méthode publique ouvre sa PROPRE
 * transaction, isolée, et n'échoue jamais de façon à faire annuler l'opération
 * appelante : toute erreur est interceptée et tracée dans
 * commission_generation_attempts, jamais relancée vers l'appelant (décision
 * AMOA #2, round 2 — cf. §0.1.2 de la conception cible). Cette isolation rend
 * l'appel sans risque même imbriqué dans la transaction métier déclenchante
 * (chargement, encaissement...) — appelé en tout dernier, une fois toutes les
 * écritures métier de l'opération faites.
 *
 * Deux voies de génération coexistent :
 *  - `genererPourCommandeVente()` — la voie RÉELLE (Phase 2+), barèmes fixes
 *    PAR_UNITE_VENDUE résolus par catégorie/produit/variante via
 *    CommissionRegleResolver, répartition via CommissionRepartitionEngine.
 *  - `genererPourCommandeVenteMargeLegacy()` — pont Phase 1 STRICTEMENT
 *    transitoire, réservé à `commissions:v2:rejouer` (preuve de parité avec
 *    l'ancien moteur). Ne jamais l'exposer comme une option métier, ne jamais
 *    l'appeler depuis un nouveau code.
 *
 * Branché sur le déclencheur réel via CommissionTriggerService::genererCommissionVente(),
 * qui bascule vers `genererPourCommandeVente()` uniquement pour les organisations
 * ayant explicitement activé leur `commission_processus` (cf. MoteurCommissionResolver) —
 * inerte pour toutes les autres.
 */
class CommissionEnveloppeGenerator
{
    /**
     * Voie réelle Phase 2+ : résout un CommissionRegle PAR_UNITE_VENDUE par
     * ligne de commande (variante > produit > catégorie exacte > globale,
     * décision AMOA #3), agrège en une seule enveloppe par cible (décision
     * AMOA #6), répartit via CommissionRepartitionEngine (décision : le
     * moteur générique, pas le contournement Phase 1).
     */
    public static function genererPourCommandeVente(
        CommandeVente $commande,
        CommissionGenerationDeclenchePar $declenchePar = CommissionGenerationDeclenchePar::SYSTEME,
        ?string $declencheurUserId = null,
    ): void {
        self::executerAvecTentative(
            $commande, CommissionProcessus::CODE_VENTE, $declenchePar, $declencheurUserId,
            fn (CommissionProcessus $processus) => self::genererParReglesDansTransaction($commande, $processus),
        );
    }

    /**
     * Pont Phase 1 — reproduit exactement l'ancienne formule de
     * CommissionCalculator (marge de l'opération, répartie par pourcentage),
     * publiée sur le nouveau schéma. RÉSERVÉ à commissions:v2:rejouer.
     */
    public static function genererPourCommandeVenteMargeLegacy(
        CommandeVente $commande,
        CommissionGenerationDeclenchePar $declenchePar = CommissionGenerationDeclenchePar::SYSTEME,
        ?string $declencheurUserId = null,
    ): void {
        self::executerAvecTentative(
            $commande, CommissionProcessus::CODE_VENTE, $declenchePar, $declencheurUserId,
            fn (CommissionProcessus $processus) => self::genererMargeLegacyDansTransaction($commande, $processus),
        );
    }

    /**
     * Encapsule le cycle commun aux deux voies : résolution du processus actif,
     * idempotence, transaction isolée, traçabilité de la tentative — jamais de
     * rethrow vers l'appelant (décision AMOA #2, round 2).
     */
    private static function executerAvecTentative(
        CommandeVente $commande,
        string $processusCode,
        CommissionGenerationDeclenchePar $declenchePar,
        ?string $declencheurUserId,
        \Closure $generation,
    ): void {
        $processus = CommissionProcessus::query()
            ->where('organization_id', $commande->organization_id)
            ->where('code', $processusCode)
            ->where('statut', CommissionActivationStatut::ACTIF->value)
            ->first();

        if (! $processus) {
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
            DB::transaction(fn () => $generation($processus));

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

    // ── Voie réelle Phase 2+ : barèmes fixes PAR_UNITE_VENDUE ────────────────

    private static function genererParReglesDansTransaction(CommandeVente $commande, CommissionProcessus $processus): void
    {
        $commande->loadMissing(['lignes.variante.produit.categorie', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire']);

        $vehicule = $commande->vehicule;
        if (! $vehicule) {
            throw new InvalidArgumentException("La commande {$commande->id} ne possède pas de véhicule lié.");
        }

        $earnedAt = Carbon::today();
        $lignes = $commande->lignes;

        $cibles = [CommissionCibleType::CODE_PROPRIETAIRE, CommissionCibleType::CODE_EQUIPE_LIVRAISON];

        /** @var array<string, array<int, array{ligne: CommandeVenteLigne, montant: float, regle: CommissionRegle}>> $lignesParCible */
        $lignesParCible = [];

        foreach ($lignes as $ligne) {
            $variante = $ligne->variante;
            $produit = $variante?->produit;
            $categorie = $produit?->categorie;

            foreach ($cibles as $cibleCode) {
                $regle = CommissionRegleResolver::resolve(
                    $commande->organization_id,
                    $processus->id,
                    $cibleCode,
                    $variante?->id,
                    $produit?->id,
                    $categorie?->id,
                    $earnedAt,
                );

                // Absence de règle = 0 pour cette cible sur cette ligne, jamais une
                // erreur (décision AMOA #4) — on passe simplement à la ligne suivante.
                if (! $regle || $regle->unite_calcul !== CommissionUniteCalcul::PAR_UNITE_VENDUE) {
                    continue;
                }

                $montantLigne = round((float) $ligne->quantite_chargee * (float) $regle->montant, 2);

                $lignesParCible[$cibleCode][] = [
                    'ligne' => $ligne,
                    'variante' => $variante,
                    'categorie' => $categorie,
                    'montant' => $montantLigne,
                    'regle' => $regle,
                ];
            }
        }

        // Tout-ou-rien : toutes les cibles collectives doivent être résolvables,
        // sinon aucune enveloppe n'est créée pour l'opération (décision AMOA #4,
        // cf. §D de la conception cible).
        $erreurs = [];
        $enveloppesACreer = [];

        foreach ($lignesParCible as $cibleCode => $contributions) {
            $montantTotal = round(array_sum(array_column($contributions, 'montant')), 2);

            if ($cibleCode === CommissionCibleType::CODE_PROPRIETAIRE) {
                if (! $vehicule->proprietaire_id) {
                    $erreurs[] = "Cible {$cibleCode} : véhicule sans propriétaire.";

                    continue;
                }
                $enveloppesACreer[$cibleCode] = [
                    'montant' => $montantTotal,
                    'cible_id' => $vehicule->proprietaire_id,
                    'contributions' => $contributions,
                    'parts' => [[
                        'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
                        'beneficiaire_id' => $vehicule->proprietaire_id,
                        'taux' => null,
                        'montant' => $montantTotal,
                    ]],
                ];

                continue;
            }

            if ($cibleCode === CommissionCibleType::CODE_EQUIPE_LIVRAISON) {
                if (! $vehicule->equipe) {
                    $erreurs[] = "Cible {$cibleCode} : aucune équipe de livraison configurée pour le véhicule.";

                    continue;
                }

                // Toujours synchronisé pour disposer d'un CommissionGroupe stable (cible_id,
                // traçabilité inchangée) — sa propre logique de rescale/blended % n'est en
                // revanche plus utilisée pour le calcul du montant ci-dessous : le partage
                // Livraison est désormais défini PAR CATÉGORIE (décision AMOA post-Phase 2),
                // jamais un seul pourcentage valable pour toute la commande.
                $groupe = CommissionGroupeSyncService::syncEquipeLivraisonVehicule($vehicule);

                $parCategorie = collect($contributions)->groupBy(
                    fn (array $c) => $c['categorie']?->id ?? 'sans_categorie'
                );

                $montantParBeneficiaire = [];
                $typeParBeneficiaire = [];
                $repartitionEchouee = false;

                foreach ($parCategorie as $categorieId => $contribsCategorie) {
                    if ($categorieId === 'sans_categorie') {
                        $erreurs[] = "Cible {$cibleCode} : une ligne sans catégorie ne peut pas résoudre de partage Livraison.";
                        $repartitionEchouee = true;

                        continue;
                    }

                    $montantCategorie = round((float) $contribsCategorie->sum('montant'), 2);

                    $partages = EquipeLivraisonPartageCategorie::where('equipe_id', $vehicule->equipe->id)
                        ->where('categorie_id', $categorieId)
                        ->get();

                    if ($partages->isEmpty()) {
                        $erreurs[] = "Cible {$cibleCode} : partage non configuré pour cette équipe sur la catégorie {$categorieId} — à régulariser.";
                        $repartitionEchouee = true;

                        continue;
                    }

                    try {
                        $partsCategorie = CommissionRepartitionEngine::repartir($montantCategorie, $partages);
                    } catch (InvalidArgumentException $e) {
                        $erreurs[] = "Cible {$cibleCode} : {$e->getMessage()}";
                        $repartitionEchouee = true;

                        continue;
                    }

                    foreach ($partsCategorie as $p) {
                        $beneficiaireId = $p['beneficiaire_id'];
                        $montantParBeneficiaire[$beneficiaireId] = ($montantParBeneficiaire[$beneficiaireId] ?? 0.0) + $p['montant'];
                        $typeParBeneficiaire[$beneficiaireId] = $p['beneficiaire_type'];
                    }
                }

                if ($repartitionEchouee) {
                    continue;
                }

                $enveloppesACreer[$cibleCode] = [
                    'montant' => $montantTotal,
                    'cible_id' => $groupe->id,
                    'contributions' => $contributions,
                    // Un seul taux "de la commande" n'existe plus (il varie par catégorie) —
                    // laissé null, jamais un pourcentage moyen trompeur.
                    'parts' => array_map(fn (string $beneficiaireId) => [
                        'beneficiaire_type' => $typeParBeneficiaire[$beneficiaireId],
                        'beneficiaire_id' => $beneficiaireId,
                        'taux' => null,
                        'montant' => round($montantParBeneficiaire[$beneficiaireId], 2),
                    ], array_keys($montantParBeneficiaire)),
                ];
            }
        }

        if (! empty($erreurs)) {
            throw new InvalidArgumentException(implode(' | ', $erreurs));
        }

        foreach ($enveloppesACreer as $cibleCode => $e) {
            $enveloppe = CommissionEnveloppe::create([
                'organization_id' => $commande->organization_id,
                'source_type' => CommandeVente::class,
                'source_id' => $commande->id,
                'processus_id' => $processus->id,
                'cible_type' => $cibleCode,
                'cible_id' => $e['cible_id'],
                'montant_total' => $e['montant'],
                'earned_at' => $earnedAt,
                'statut' => StatutCommission::CREEE->value,
            ]);

            foreach ($e['contributions'] as $c) {
                CommissionEnveloppeLigne::create([
                    'enveloppe_id' => $enveloppe->id,
                    'source_ligne_type' => CommandeVenteLigne::class,
                    'source_ligne_id' => $c['ligne']->id,
                    'variante_id' => $c['variante']?->id,
                    'categorie_id_snapshot' => $c['categorie']?->id,
                    'commission_regle_id' => $c['regle']->id,
                    'quantite' => $c['ligne']->quantite_chargee,
                    'unite_calcul_snapshot' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                    'montant_ligne' => $c['montant'],
                ]);
            }

            foreach ($e['parts'] as $p) {
                CommissionEnveloppePart::create([
                    'enveloppe_id' => $enveloppe->id,
                    'beneficiaire_type' => $p['beneficiaire_type'],
                    'beneficiaire_id' => $p['beneficiaire_id'],
                    'taux_repartition_snapshot' => $p['taux'],
                    'montant_brut' => $p['montant'],
                    'montant_net' => $p['montant'],
                    'statut' => StatutCommission::CREEE->value,
                    'origine' => OrigineCommissionPart::THEORIQUE->value,
                ]);
            }
        }
    }

    // ── Pont Phase 1 (legacy, réservé au replay) ─────────────────────────────

    private static function genererMargeLegacyDansTransaction(CommandeVente $commande, CommissionProcessus $processus): void
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

        // Parité Phase 1 EXACTE : chaque part est calculée directement depuis le taux
        // ORIGINAL de equipe_livreurs (round(marge × taux_original / 100, 2)), en un
        // seul arrondi — rigoureusement la même formule et donc les mêmes montants au
        // centime près que l'ancien CommissionCalculator. CommissionRepartitionEngine
        // (moteur générique, basé sur le pourcentage RESCALÉ et stocké à 2 décimales
        // dans commission_groupe_membres) N'EST PAS utilisé ici pour le calcul du
        // montant : un second arrondi intermédiaire (rescale puis application) casse
        // la parité bit-à-bit, constaté empiriquement sur des cas à 3+ livreurs
        // (cf. tests/Feature/CommissionParitePhase1Test.php). Le moteur générique
        // reste inchangé et sera la seule voie utilisée à partir de la Phase 2,
        // quand il n'existera plus de "formule originale" à reproduire.
        $partsCalculees = [];
        $montantLivraison = 0.0;
        foreach ($equipe->membres as $membre) {
            $montant = round($margeOperation * (float) $membre->taux_commission / 100, 2);
            $montantLivraison += $montant;
            $partsCalculees[] = [
                'livreur_id' => $membre->livreur_id,
                'taux_original' => (float) $membre->taux_commission,
                'montant' => $montant,
            ];
        }
        $montantLivraison = round($montantLivraison, 2);

        $enveloppeLivraison = CommissionEnveloppe::create([
            'organization_id' => $commande->organization_id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => $groupe->id,
            // Somme des parts réellement calculées (pas un pourcentage rescalé indépendant)
            // — garantit SUM(parts) === montant_total par construction, toujours.
            'montant_total' => $montantLivraison,
            'earned_at' => $earnedAt,
            'statut' => StatutCommission::CREEE->value,
        ]);

        self::creerLignesTracabilite($enveloppeLivraison, $lignes, $regleLivraison, (100 - $tauxProprietaire) / 100);

        $rescaledParGroupe = $membresActifs->keyBy('beneficiaire_id');
        foreach ($partsCalculees as $p) {
            CommissionEnveloppePart::create([
                'enveloppe_id' => $enveloppeLivraison->id,
                'beneficiaire_type' => CommissionGroupeMembre::TYPE_LIVREUR,
                'beneficiaire_id' => $p['livreur_id'],
                // Snapshot informatif : le % rescalé tel qu'il vit dans commission_groupe_membres
                // (Phase 2+), même si ce n'est pas lui qui a servi au calcul du montant ci-dessus.
                'taux_repartition_snapshot' => $rescaledParGroupe->get($p['livreur_id'])?->part_pourcentage,
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
