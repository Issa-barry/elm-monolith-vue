<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionGenerationDeclenchePar;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\OrigineCommissionPart;
use App\Enums\PrestataireType;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppeLigne;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\Parametre;
use App\Models\Prestataire;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Moteur unique de génération d'enveloppes de commission vente. Chaque méthode
 * publique ouvre sa PROPRE transaction, isolée, et n'échoue jamais de façon à
 * faire annuler l'opération appelante : toute erreur est interceptée et tracée
 * dans commission_generation_attempts, jamais relancée vers l'appelant. Cette
 * isolation rend l'appel sans risque même imbriqué dans la transaction métier
 * déclenchante (chargement, encaissement...) — appelé en tout dernier, une
 * fois toutes les écritures métier de l'opération faites.
 *
 * Barèmes fixes PAR_UNITE_VENDUE résolus par catégorie/produit/variante via
 * CommissionRegleResolver, répartition via CommissionRepartitionEngine.
 * Branché sur le déclencheur réel via CommissionTriggerService::genererCommissionVente().
 */
class CommissionEnveloppeGenerator
{
    /**
     * Résout un CommissionRegle PAR_UNITE_VENDUE par ligne de commande
     * (variante > produit > catégorie exacte > globale, décision AMOA #3),
     * agrège en une seule enveloppe par cible (décision AMOA #6), répartit
     * via CommissionRepartitionEngine.
     */
    public static function genererPourCommandeVente(
        CommandeVente $commande,
        CommissionGenerationDeclenchePar $declenchePar = CommissionGenerationDeclenchePar::SYSTEME,
        ?string $declencheurUserId = null,
    ): void {
        // Éligibilité aux commissions figée au moment de la commande
        // (commission_eligible_snapshot, dérivée de Vehicule::livraison_vente) — notion
        // indépendante du mode de tarification (prix_vente/prix_usine, cf. ModeTarification).
        // Voir VehiculeCommandeContextResolver. Un véhicule non éligible ne doit jamais
        // générer de commission, quel que soit son état actuel.
        if (! $commande->commission_eligible_snapshot) {
            return;
        }

        self::executerAvecTentative(
            $commande, CommissionProcessus::CODE_VENTE, $declenchePar, $declencheurUserId,
            fn (CommissionProcessus $processus) => self::genererParReglesDansTransaction($commande, $processus),
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
        // Aucune notion d'« activation » séparée : toute organisation utilise le même
        // moteur de commission dès sa création — le processus est provisionné à la
        // volée s'il n'existe pas encore (ex: organisation fraîchement créée), jamais
        // un pré-requis silencieusement bloquant.
        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $commande->organization_id, 'code' => $processusCode],
            [
                'libelle' => 'Vente',
                'declencheur' => Parametre::getDeclencheurCommissionVente($commande->organization_id)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );

        // Verrou de ligne sur la commande le temps de vérifier l'idempotence et de
        // générer : deux déclenchements concurrents pour la même commande (retry,
        // webhook, double appel résiduel côté déclencheur) se sérialisent — le second,
        // une fois le verrou obtenu, retrouve l'enveloppe déjà créée et s'arrête, au lieu
        // de produire deux tentatives quasi simultanées (cf. incident CMD-230826-004, 2
        // tentatives ERREUR à 1s d'écart). Défense en profondeur : le correctif du double
        // appel constaté est côté déclencheurs (cf. EncaissementVenteController) — ce
        // verrou couvre tout futur appel qui dupliquerait malgré tout le déclenchement.
        // Transaction imbriquée (savepoint) sans risque : la génération elle-même garde
        // sa propre transaction interne isolée juste en dessous.
        DB::transaction(function () use ($commande, $processus, $declenchePar, $declencheurUserId, $generation) {
            CommandeVente::whereKey($commande->id)->lockForUpdate()->value('id');

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
        });
    }

    // ── Voie réelle Phase 2+ : barèmes fixes PAR_UNITE_VENDUE ────────────────

    private static function genererParReglesDansTransaction(CommandeVente $commande, CommissionProcessus $processus): void
    {
        $commande->loadMissing(['lignes.variante.produit.categorie', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire', 'site']);

        $vehicule = $commande->vehicule;
        if (! $vehicule) {
            throw new InvalidArgumentException("La commande {$commande->id} ne possède pas de véhicule lié.");
        }

        $earnedAt = Carbon::today();
        $lignes = $commande->lignes;

        $cibles = [CommissionCibleType::CODE_PROPRIETAIRE, CommissionCibleType::CODE_EQUIPE_LIVRAISON];
        // Site : cible directe supplémentaire, ancrée sur le SITE métier de l'opération (pas le
        // véhicule) — s'applique à TOUT type de site dès qu'une opération lui est rattachée
        // (décision produit 2026-08-21 : jamais limité aux dépôts, jamais conditionné à un
        // gérant/une fonction/un rôle). Un site absent laisse simplement la cible hors de
        // $cibles : aucune tentative, aucune erreur — mirroring le comportement "pas de règle
        // configurée = pas de cible" déjà appliqué partout ailleurs.
        if ($commande->site) {
            $cibles[] = CommissionCibleType::CODE_SITE;
        }
        // Consultant : toujours candidate, contrairement à Site — elle ne dépend d'aucune donnée
        // de la commande, seulement d'une désignation au niveau organisation (cf.
        // CommissionConsultantAffectation). Une organisation qui n'a jamais configuré de barème
        // consultant ne verra jamais cette cible produire de contribution (absence de règle = 0,
        // décision AMOA #4) : aucun impact pour les organisations existantes.
        $cibles[] = CommissionCibleType::CODE_CONSULTANT;

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
                    $vehicule->type_vehicule_id,
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
                // traçabilité inchangée) — le partage réel n'est cependant plus calculé via ce
                // groupe : le partage Livreur est défini PAR CATÉGORIE, en montants GNF entiers
                // fixes (equipe_livraison_partages_categorie), jamais un pourcentage.
                $groupe = CommissionGroupeSyncService::syncEquipeLivraisonVehicule($vehicule);

                $parCategorie = collect($contributions)->groupBy(
                    fn (array $c) => $c['categorie']?->id ?? 'sans_categorie'
                );

                $montantParBeneficiaire = [];
                $montantUnitaireParBeneficiaire = [];
                $typeParBeneficiaire = [];
                $montantTotalEquipe = 0;
                $repartitionEchouee = false;

                foreach ($parCategorie as $categorieId => $contribsCategorie) {
                    if ($categorieId === 'sans_categorie') {
                        $erreurs[] = "Cible {$cibleCode} : une ligne sans catégorie ne peut pas résoudre de partage Livreur.";
                        $repartitionEchouee = true;

                        continue;
                    }

                    // Barème Livreur résolu au niveau CATÉGORIE uniquement (jamais variante/
                    // produit) : le partage entre livreurs n'a lui-même jamais été défini plus
                    // finement qu'une catégorie (cf. EquipeLivraisonPartageCategorie) — même
                    // résolution que celle utilisée pour valider la config à la sauvegarde de
                    // l'équipe (EquipeLivraisonController::validatePartagesCategorie), pour
                    // garantir que l'enveloppe validée à la saisie et l'enveloppe utilisée à la
                    // génération sont toujours identiques. Une éventuelle règle plus spécifique
                    // (variante/produit) sur une ligne de cette catégorie n'est donc pas prise en
                    // compte ici, contrairement aux autres cibles — limite assumée, cohérente
                    // avec le fait que le partage Livreur n'existe jamais à un grain plus fin.
                    $regleCategorie = CommissionRegleResolver::resolve(
                        $commande->organization_id,
                        $processus->id,
                        $cibleCode,
                        null,
                        null,
                        $categorieId,
                        $earnedAt,
                        $vehicule->type_vehicule_id,
                    );

                    $enveloppeUnitaire = (int) round((float) ($regleCategorie?->montant ?? 0));

                    // Barème Livreur configuré à 0 pour cette catégorie : valeur métier valide
                    // ("aucune commission à distribuer"), jamais un partage à exiger ni une
                    // erreur — rien à répartir, on passe à la suivante.
                    if ($enveloppeUnitaire <= 0) {
                        continue;
                    }

                    $quantiteCategorie = (int) $contribsCategorie->sum(fn (array $c) => (float) $c['ligne']->quantite_chargee);

                    $partages = EquipeLivraisonPartageCategorie::where('equipe_id', $vehicule->equipe->id)
                        ->where('categorie_id', $categorieId)
                        ->actifA($earnedAt)
                        ->get();

                    if ($partages->isEmpty()) {
                        $erreurs[] = "Cible {$cibleCode} : partage non configuré pour cette équipe sur la catégorie {$categorieId} — à régulariser.";
                        $repartitionEchouee = true;

                        continue;
                    }

                    $membresValidation = $partages->map(fn (EquipeLivraisonPartageCategorie $p) => (object) [
                        'beneficiaire_id' => $p->livreur_id,
                        'montant_unitaire' => $p->montant_unitaire,
                    ]);

                    try {
                        CommissionPartageLivraisonValidator::valider($membresValidation, $enveloppeUnitaire);
                    } catch (InvalidArgumentException $e) {
                        $erreurs[] = "Cible {$cibleCode} : {$e->getMessage()}";
                        $repartitionEchouee = true;

                        continue;
                    }

                    foreach ($partages as $partage) {
                        $beneficiaireId = $partage->livreur_id;
                        $montantMembre = $quantiteCategorie * (int) $partage->montant_unitaire;

                        $montantParBeneficiaire[$beneficiaireId] = ($montantParBeneficiaire[$beneficiaireId] ?? 0) + $montantMembre;
                        $montantUnitaireParBeneficiaire[$beneficiaireId] = (int) $partage->montant_unitaire;
                        $typeParBeneficiaire[$beneficiaireId] = CommissionEnveloppePart::TYPE_LIVREUR;
                    }

                    $montantTotalEquipe += $quantiteCategorie * $enveloppeUnitaire;
                }

                if ($repartitionEchouee) {
                    continue;
                }

                // Toutes les catégories contributrices avaient un barème Livreur à 0 (ou
                // aucune n'a résolu de règle) : rien à distribuer, aucune enveloppe à créer —
                // même silence que l'absence de règle (décision AMOA #4).
                if (empty($montantParBeneficiaire)) {
                    continue;
                }

                $enveloppesACreer[$cibleCode] = [
                    'montant' => $montantTotalEquipe,
                    'cible_id' => $groupe->id,
                    'contributions' => $contributions,
                    'parts' => array_map(fn (string $beneficiaireId) => [
                        'beneficiaire_type' => $typeParBeneficiaire[$beneficiaireId],
                        'beneficiaire_id' => $beneficiaireId,
                        'taux' => null,
                        'montant_unitaire' => $montantUnitaireParBeneficiaire[$beneficiaireId],
                        'montant' => $montantParBeneficiaire[$beneficiaireId],
                    ], array_keys($montantParBeneficiaire)),
                ];

                continue;
            }

            if ($cibleCode === CommissionCibleType::CODE_SITE) {
                $site = $commande->site;

                // Bénéficiaire = le Site lui-même, directement — jamais un gérant, un employé, ou
                // un CommissionGroupe. Aucune vérification de gérant/fonction/rôle/statut/compte
                // utilisateur (décision produit 2026-08-21) : mode DIRECT au même titre que
                // CODE_PROPRIETAIRE ci-dessus, pas de répartition à calculer.
                if (! $site) {
                    $erreurs[] = "Cible {$cibleCode} : commande sans site.";

                    continue;
                }

                $enveloppesACreer[$cibleCode] = [
                    'montant' => $montantTotal,
                    'cible_id' => $site->id,
                    'contributions' => $contributions,
                    'parts' => [[
                        'beneficiaire_type' => CommissionEnveloppePart::TYPE_SITE,
                        'beneficiaire_id' => $site->id,
                        'taux' => null,
                        'montant' => $montantTotal,
                    ]],
                ];

                continue;
            }

            if ($cibleCode === CommissionCibleType::CODE_CONSULTANT) {
                // Le bénéficiaire est porté par chaque règle de catégorie. Le repli sur
                // l'ancienne affectation globale ne sert qu'aux règles historiques créées
                // avant l'introduction du paramétrage par catégorie.
                $affectationHistorique = CommissionConsultantAffectation::actifPour(
                    $commande->organization_id,
                );
                $parConsultant = collect($contributions)->groupBy(
                    fn (array $contribution) => $contribution['regle']->consultant_id
                        ?? $affectationHistorique?->prestataire_id
                        ?? 'sans_consultant',
                );

                foreach ($parConsultant as $consultantId => $contributionsConsultant) {
                    if ($consultantId === 'sans_consultant') {
                        $erreurs[] = "Cible {$cibleCode} : une catégorie n'a aucun consultant désigné.";

                        continue;
                    }

                    $consultantActif = Prestataire::whereKey($consultantId)
                        ->where('organization_id', $commande->organization_id)
                        ->where('type', PrestataireType::CONSULTANT->value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $consultantActif) {
                        $erreurs[] = "Cible {$cibleCode} : le consultant {$consultantId} n'est plus actif.";

                        continue;
                    }

                    $montantConsultant = round(
                        (float) $contributionsConsultant->sum('montant'),
                        2,
                    );
                    $enveloppesACreer["{$cibleCode}:{$consultantId}"] = [
                        'cible_type' => $cibleCode,
                        'montant' => $montantConsultant,
                        'cible_id' => $consultantId,
                        'contributions' => $contributionsConsultant->all(),
                        'parts' => [[
                            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PRESTATAIRE,
                            'beneficiaire_id' => $consultantId,
                            'taux' => null,
                            'montant' => $montantConsultant,
                        ]],
                    ];
                }
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
                'cible_type' => $e['cible_type'] ?? $cibleCode,
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
                    'montant_unitaire_snapshot' => $p['montant_unitaire'] ?? null,
                    'montant_brut' => $p['montant'],
                    'montant_net' => $p['montant'],
                    'statut' => StatutCommission::CREEE->value,
                    'origine' => OrigineCommissionPart::THEORIQUE->value,
                ]);
            }
        }
    }
}
