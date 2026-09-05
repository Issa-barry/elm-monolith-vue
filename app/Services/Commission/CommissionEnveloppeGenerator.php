<?php

namespace App\Services\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionGenerationDeclenchePar;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionUniteCalcul;
use App\Enums\NatureOperation;
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
use App\Models\Prestataire;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Notifications\CommissionGenereeNotification;
use App\Notifications\CommissionManquanteNotification;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\PushBodyFormatter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Moteur unique de génération d'enveloppes de commission — vente (standard ou distribution
 * client) et transfert logistique, pour toute organisation (moteur unique depuis le
 * 03/09/2026, cf. CommissionTriggerService — la bascule par organisation a été retirée).
 * Chaque méthode publique ouvre sa PROPRE transaction, isolée, et n'échoue jamais de façon à
 * faire annuler l'opération appelante :
 * toute erreur est interceptée et tracée dans commission_generation_attempts, jamais relancée vers
 * l'appelant. Cette isolation rend l'appel sans risque même imbriqué dans la transaction métier
 * déclenchante (chargement, encaissement...) — appelé en tout dernier, une fois toutes les
 * écritures métier de l'opération faites.
 *
 * Barèmes fixes PAR_UNITE_VENDUE résolus par catégorie/produit/variante via
 * CommissionRegleResolver, répartition Livreur en montants fixes via EquipeLivraisonPartageCategorie
 * (jamais un pourcentage). Le couplage à une source précise (CommandeVente/TransfertLogistique) est
 * isolé dans les deux adaptateurs contexteDepuis*() ; genererDepuisContexte() ne connaît que
 * CommissionOperationContext, un objet générique.
 */
class CommissionEnveloppeGenerator
{
    /**
     * Résout un CommissionRegle PAR_UNITE_VENDUE par ligne de commande
     * (variante > produit > catégorie exacte > globale, décision AMOA #3),
     * agrège en une seule enveloppe par cible (décision AMOA #6). Le processus
     * (vente ou distribution_client) est déterminé par CommandeVente::nature_operation,
     * figé à la création de la commande — jamais recalculé ici.
     *
     * Révisé le 02/09/2026 (décision produit) : "processus métier" (identité de la commission,
     * reporting, historique) et "barème" (montant réellement appliqué) sont deux notions
     * distinctes — une distribution client reste taguée CODE_DISTRIBUTION_CLIENT (jamais
     * silencieusement reclassée), mais tant qu'aucune CommissionRegle active ne lui est
     * explicitement propre, le montant appliqué est celui de CODE_LOGISTIQUE_TRANSFERT (cf.
     * CommissionProcessusDefaults::processusResolutionBareme(), source unique de ce repli).
     */
    public static function genererPourCommandeVente(
        CommandeVente $commande,
        CommissionGenerationDeclenchePar $declenchePar = CommissionGenerationDeclenchePar::SYSTEME,
        ?string $declencheurUserId = null,
    ): void {
        // Chantier 2A (05/09/2026, généralisation de l'ancien correctif Grossiste) : il n'y a
        // plus de verrou global ici. commission_eligible_snapshot (dérivé de
        // Vehicule::livraison_vente/livraison_logistique, cf. VehiculeCommandeContextResolver)
        // ne conditionne plus QUE les cibles PROPRIETAIRE/EQUIPE_LIVRAISON — transmis via
        // CommissionOperationContext::$vehiculeEligibleCommission, lu uniquement dans
        // genererDepuisContexte(). SITE et CONSULTANT sont désormais toujours évalués, pour
        // tout type de client, avec ou sans véhicule — cf. docs/commissions.md.
        //
        // Identité du processus résolue par CommissionProcessusDefaults::identiteCodePourVente() —
        // source UNIQUE partagée avec CommandeVenteController::ensurePartageLivraisonCategorieConfigure()
        // (chantier « Transfert grossiste », 05/09/2026, cf. docs/grossiste.md) : jamais un second
        // calcul indépendant qui pourrait diverger.
        $processusCode = CommissionProcessusDefaults::identiteCodePourVente(
            $commande->nature_operation,
            $commande->client?->type,
            $commande->mode_remise_grossiste,
        );

        $ctx = self::contexteDepuisCommandeVente($commande);

        self::executerAvecTentative(
            $ctx, $processusCode, $declenchePar, $declencheurUserId,
            fn (CommissionProcessus $identite) => self::genererDepuisContexte(
                $ctx, $identite, CommissionProcessusDefaults::processusResolutionBareme($identite),
            ),
        );
    }

    /**
     * Génère la commission d'un transfert logistique via le moteur générique — le seul moteur
     * de commission logistique depuis le 03/09/2026 (retrait de la bascule par organisation
     * et de l'ancien CommissionLogistiqueService). $champQuantite ('quantite_chargee' ou
     * 'quantite_recue') est décidé par l'appelant selon le déclencheur configuré pour
     * l'organisation, jamais ici.
     */
    public static function genererPourTransfertLogistique(
        TransfertLogistique $transfert,
        string $champQuantite,
        CommissionGenerationDeclenchePar $declenchePar = CommissionGenerationDeclenchePar::SYSTEME,
        ?string $declencheurUserId = null,
    ): void {
        $ctx = self::contexteDepuisTransfertLogistique($transfert, $champQuantite);

        self::executerAvecTentative(
            $ctx, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, $declenchePar, $declencheurUserId,
            // Jamais de repli pour un transfert : logistique_transfert est déjà son propre barème.
            fn (CommissionProcessus $identite) => self::genererDepuisContexte($ctx, $identite, $identite),
        );
    }

    // ── Adaptateurs source → contexte générique ──────────────────────────────

    private static function contexteDepuisCommandeVente(CommandeVente $commande): CommissionOperationContext
    {
        $commande->loadMissing(['lignes.variante.produit.categorie', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire', 'site']);

        // distribution_client se calcule sur les quantités réellement RÉCEPTIONNÉES
        // (quantite_livree), jamais chargées — la validation de réception est son unique
        // déclencheur (cf. CommissionTriggerService::onReceptionDistributionValidee()), décision
        // produit du 30/08/2026 qui révise COMM-004. vente_standard reste inchangée.
        $quantiteField = $commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT
            ? 'quantite_livree'
            : 'quantite_chargee';

        return new CommissionOperationContext(
            organizationId: $commande->organization_id,
            sourceType: CommandeVente::class,
            sourceId: $commande->id,
            reference: $commande->reference,
            montantReference: (float) $commande->total_commande,
            vehicule: $commande->vehicule,
            site: $commande->site,
            earnedAt: Carbon::today(),
            sourceLigneType: CommandeVenteLigne::class,
            quantiteField: $quantiteField,
            lignes: $commande->lignes,
            vehiculeEligibleCommission: (bool) $commande->commission_eligible_snapshot,
        );
    }

    /**
     * Site cible toujours le site SOURCE du transfert, jamais la destination, et toujours
     * explicite (jamais une stratégie d'ancrage implicite) — décision produit explicite.
     */
    private static function contexteDepuisTransfertLogistique(TransfertLogistique $transfert, string $champQuantite): CommissionOperationContext
    {
        $transfert->loadMissing(['lignes.variante.produit.categorie', 'vehicule.equipe.membres.livreur', 'vehicule.proprietaire', 'siteSource']);

        $verbeEvenement = $champQuantite === 'quantite_recue' ? 'réceptionné' : 'chargé';

        return new CommissionOperationContext(
            organizationId: $transfert->organization_id,
            sourceType: TransfertLogistique::class,
            sourceId: $transfert->id,
            reference: $transfert->reference,
            montantReference: 0.0,
            vehicule: $transfert->vehicule,
            site: $transfert->siteSource,
            earnedAt: $transfert->date_arrivee_reelle ? Carbon::instance($transfert->date_arrivee_reelle) : Carbon::today(),
            sourceLigneType: TransfertLigne::class,
            quantiteField: $champQuantite,
            lignes: $transfert->lignes,
            // Toujours true : un TransfertLogistique exige structurellement un véhicule
            // (contrairement à une CommandeVente) — aucun équivalent de
            // commission_eligible_snapshot ici, comportement inchangé.
            vehiculeEligibleCommission: true,
            notifSourceLabel: 'transfert_logistique',
            notifLibelleOperation: 'Le transfert logistique',
            notifVerbeEvenement: $verbeEvenement,
            notifUrlPath: '/backoffice/logistique/',
            notifActionLabel: 'Voir le transfert',
        );
    }

    /**
     * Encapsule le cycle commun à toutes les sources : résolution du processus actif,
     * idempotence, transaction isolée, traçabilité de la tentative — jamais de
     * rethrow vers l'appelant (décision AMOA #2, round 2).
     */
    private static function executerAvecTentative(
        CommissionOperationContext $ctx,
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
            ['organization_id' => $ctx->organizationId, 'code' => $processusCode],
            [...CommissionProcessusDefaults::pour($ctx->organizationId, $processusCode), 'statut' => CommissionActivationStatut::ACTIF->value],
        );

        // Verrou de ligne sur la source le temps de vérifier l'idempotence et de
        // générer : deux déclenchements concurrents pour la même opération (retry,
        // webhook, double appel résiduel côté déclencheur) se sérialisent — le second,
        // une fois le verrou obtenu, retrouve l'enveloppe déjà créée et s'arrête, au lieu
        // de produire deux tentatives quasi simultanées (cf. incident CMD-230826-004, 2
        // tentatives ERREUR à 1s d'écart). Défense en profondeur : le correctif du double
        // appel constaté est côté déclencheurs (cf. EncaissementVenteController) — ce
        // verrou couvre tout futur appel qui dupliquerait malgré tout le déclenchement.
        // Transaction imbriquée (savepoint) sans risque : la génération elle-même garde
        // sa propre transaction interne isolée juste en dessous.
        DB::transaction(function () use ($ctx, $processus, $declenchePar, $declencheurUserId, $generation) {
            $ctx->sourceType::whereKey($ctx->sourceId)->lockForUpdate()->value('id');

            $dejaGenere = CommissionEnveloppe::query()
                ->where('source_type', $ctx->sourceType)
                ->where('source_id', $ctx->sourceId)
                ->exists();
            if ($dejaGenere) {
                return;
            }

            try {
                $erreursCibles = DB::transaction(fn () => $generation($processus));

                // "Succès" ne veut pas dire "une commission a réellement été créée" :
                // l'absence de barème actif pour une catégorie résout silencieusement à
                // 0 (décision AMOA #4, jamais une erreur) — correct pour le calcul, mais
                // une opération dont le fait générateur est passé sans AUCUNE enveloppe
                // créée doit être signalée, sinon ça passe totalement inaperçu (incident
                // 2026-08-25 : CMD-250826-007 facturée et payée, catégorie jamais
                // configurée dans Paramètres > Commissions, aucune alerte).
                $auMoinsUneEnveloppe = CommissionEnveloppe::query()
                    ->where('source_type', $ctx->sourceType)
                    ->where('source_id', $ctx->sourceId)
                    ->exists();

                // Chantier 2A (05/09/2026, indépendance des cibles) : trois statuts possibles,
                // jamais un simple binaire SUCCES/ERREUR — PARTIEL couvre "certaines cibles
                // générées avec succès, au moins une autre à régulariser", sans plus jamais
                // annuler les cibles correctement résolues (révise l'ancienne décision AMOA #4
                // "tout-ou-rien", cf. genererDepuisContexte()).
                $statut = match (true) {
                    empty($erreursCibles) => CommissionGenerationStatut::SUCCES,
                    $auMoinsUneEnveloppe => CommissionGenerationStatut::PARTIEL,
                    default => CommissionGenerationStatut::ERREUR,
                };

                CommissionGenerationAttempt::create([
                    'organization_id' => $ctx->organizationId,
                    'source_type' => $ctx->sourceType,
                    'source_id' => $ctx->sourceId,
                    'processus_id' => $processus->id,
                    'statut' => $statut->value,
                    'motif_erreur' => empty($erreursCibles) ? null : implode(' | ', $erreursCibles),
                    'detail_erreur' => empty($erreursCibles) ? null : ['erreurs' => $erreursCibles],
                    'declenchee_par' => $declenchePar->value,
                    'created_by' => $declencheurUserId,
                ]);

                // Les bénéficiaires connectés (propriétaire/livreur) sont notifiés de leur part
                // dès qu'au moins une enveloppe existe — y compris en PARTIEL : une cible cassée
                // ne doit jamais retarder la notification des cibles correctement résolues.
                if ($auMoinsUneEnveloppe) {
                    self::notifierCommissionGeneree($ctx);
                }

                // Alerte régularisation : succès total mais 0 enveloppe (aucun barème nulle
                // part, cas silencieux légitime), ou au moins une cible en erreur (PARTIEL/ERREUR).
                if (! $auMoinsUneEnveloppe || ! empty($erreursCibles)) {
                    self::alerterCommissionManquante(
                        $ctx,
                        $declencheurUserId,
                        empty($erreursCibles) ? null : implode(' | ', $erreursCibles),
                    );
                }
            } catch (InvalidArgumentException $e) {
                // Filet de sécurité pour une erreur métier vraiment imprévue : depuis le
                // chantier 2A, genererDepuisContexte() ne lève plus cette exception pour les cas
                // connus (cible dont le bénéficiaire est introuvable) — elle les retourne à la
                // place, cf. ci-dessus.
                Log::warning('Génération commission v2 en erreur : '.$e->getMessage(), [
                    'source_type' => $ctx->sourceType,
                    'source_id' => $ctx->sourceId,
                ]);

                CommissionGenerationAttempt::create([
                    'organization_id' => $ctx->organizationId,
                    'source_type' => $ctx->sourceType,
                    'source_id' => $ctx->sourceId,
                    'processus_id' => $processus->id,
                    'statut' => CommissionGenerationStatut::ERREUR->value,
                    'motif_erreur' => $e->getMessage(),
                    'detail_erreur' => ['erreurs' => [$e->getMessage()]],
                    'declenchee_par' => $declenchePar->value,
                    'created_by' => $declencheurUserId,
                ]);

                self::alerterCommissionManquante($ctx, $declencheurUserId, $e->getMessage());

                // Volontairement pas de rethrow : un échec de génération n'est jamais
                // une erreur de l'opération métier qui l'a déclenchée. L'opération
                // reste "à régulariser", jamais rollbackée.
            }
        });
    }

    /**
     * Alerte l'organisation (administrateurs + utilisateur à l'origine de
     * l'événement déclencheur) qu'une opération n'a produit AUCUNE commission —
     * échec technique (motif renseigné) ou succès silencieux (aucun barème actif,
     * cf. ci-dessus). Ne doit JAMAIS interrompre la génération ni l'opération métier
     * appelante : toute erreur d'envoi (mail indisponible, etc.) est avalée et
     * journalisée, jamais relancée — même garantie que le reste de cette classe.
     */
    private static function alerterCommissionManquante(
        CommissionOperationContext $ctx,
        ?string $declencheurUserId,
        ?string $motifErreur,
    ): void {
        try {
            $notification = new CommissionManquanteNotification(
                $ctx->sourceId,
                $ctx->reference,
                $ctx->montantReference,
                $motifErreur,
                $ctx->notifLibelleOperation,
                $ctx->notifVerbeEvenement,
                $ctx->notifUrlPath,
                $ctx->notifActionLabel,
            );

            // whereHas(...) plutôt que le scope role() de Spatie : ce dernier lève
            // RoleDoesNotExist dès qu'UN SEUL des noms fournis n'existe pas encore pour
            // le guard (constaté : "super_admin" absent sur une organisation fraîche en
            // test) — un simple whereIn SQL reste robuste même si un rôle n'existe pas.
            $destinataires = User::where('organization_id', $ctx->organizationId)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin_entreprise']))
                ->get()
                ->keyBy('id');

            if ($declencheurUserId) {
                $declencheur = User::find($declencheurUserId);
                if ($declencheur) {
                    $destinataires->put($declencheur->id, $declencheur);
                }
            }

            foreach ($destinataires as $destinataire) {
                $destinataire->notify($notification);
            }
        } catch (Throwable $e) {
            Log::error('CommissionManquanteNotification : envoi échoué', [
                'source_type' => $ctx->sourceType,
                'source_id' => $ctx->sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie les bénéficiaires réellement connectés (proprietaire, livreur)
     * des parts de commission venant d'être créées pour cette opération —
     * jamais `site`/`consultant` (aucun compte utilisateur, cf.
     * BeneficiaireUserResolver). Même garantie d'isolation que
     * alerterCommissionManquante() : jamais de rethrow vers l'appelant.
     */
    private static function notifierCommissionGeneree(CommissionOperationContext $ctx): void
    {
        try {
            $parts = CommissionEnveloppePart::whereHas(
                'enveloppe',
                fn ($q) => $q->where('source_type', $ctx->sourceType)->where('source_id', $ctx->sourceId)
            )->whereIn('beneficiaire_type', [
                CommissionEnveloppePart::TYPE_PROPRIETAIRE,
                CommissionEnveloppePart::TYPE_LIVREUR,
            ])->get();

            // Clé du payload push : historique "commande_id"/"transfert_id" selon la source, cf.
            // CommissionLogistiqueService::notifierCommissionGeneree() pour le transfert legacy.
            $pushDataKey = $ctx->sourceType === TransfertLogistique::class ? 'transfert_id' : 'commande_id';

            foreach ($parts as $part) {
                $user = BeneficiaireUserResolver::resolve($part->beneficiaire_type, $part->beneficiaire_id);
                $notif = new CommissionGenereeNotification($ctx->notifSourceLabel, $ctx->sourceId, $ctx->reference, (float) $part->montant_net);
                // Réutilise le titre/message déjà construits par la notification database —
                // jamais un second texte pour le push (même événement, même sens).
                $notifData = $user ? $notif->toArray($user) : null;

                NotificationDispatcher::send(
                    $notif,
                    [$user],
                    'commissions',
                    $notifData ? fn () => [
                        'title' => $notifData['titre'],
                        'body' => PushBodyFormatter::format($notifData),
                        'data' => ['type' => 'commission.generated', $pushDataKey => $ctx->sourceId],
                    ] : null,
                );
            }
        } catch (Throwable $e) {
            Log::error('CommissionGenereeNotification : envoi échoué', [
                'source_type' => $ctx->sourceType,
                'source_id' => $ctx->sourceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Cœur générique : résolution de règles + agrégation par cible ─────────

    /**
     * $processusIdentite tague la CommissionEnveloppe créée (reporting/historique — jamais
     * recalculé après coup) ; $processusBareme est celui dont les CommissionRegle/partages sont
     * réellement lus pour calculer les montants — les deux diffèrent uniquement pour
     * distribution_client tant qu'il n'a pas sa propre configuration (cf.
     * CommissionProcessusDefaults::processusResolutionBareme()), identiques dans tous les autres cas.
     *
     * Retourne les messages des cibles dont le bénéficiaire n'a pas pu être résolu (liste vide
     * si tout s'est bien passé), jamais levés en exception depuis le chantier 2A (05/09/2026,
     * indépendance des cibles) : une cible cassée n'empêche plus la persistance des cibles
     * correctement résolues de la même opération (cf. executerAvecTentative(), qui décide du
     * statut SUCCES/PARTIEL/ERREUR à partir de ce retour). Révise l'ancienne décision AMOA #4
     * (tout-ou-rien), désormais réservée au seul cas où AUCUNE cible n'a pu être générée, cf.
     * docs/commissions.md.
     *
     * @return list<string>
     */
    private static function genererDepuisContexte(CommissionOperationContext $ctx, CommissionProcessus $processusIdentite, CommissionProcessus $processusBareme): array
    {
        $vehicule = $ctx->vehicule;

        $earnedAt = $ctx->earnedAt;
        $lignes = $ctx->lignes;

        $cibles = [];
        // PROPRIETAIRE/EQUIPE_LIVRAISON n'ont de sens qu'avec un véhicule ELIGIBLE (chantier 2A,
        // 05/09/2026) : $vehicule seul ne suffit pas — un véhicule peut être présent mais non
        // autorisé pour l'usage réellement concerné par l'opération (ex: véhicule vente-only
        // utilisé pour une distribution), auquel cas $ctx->vehiculeEligibleCommission est false
        // (cf. VehiculeCommandeContextResolver). Absent pour toute commande sans véhicule, quel
        // que soit le type de client (généralisation de l'ancienne exception Grossiste, cf.
        // docs/commissions.md) — un transfert logistique a toujours vehiculeEligibleCommission
        // true. Un véhicule éligible mais sans équipe reste géré plus bas (erreur "à
        // régulariser"), comportement inchangé.
        if ($vehicule && $ctx->vehiculeEligibleCommission) {
            $cibles[] = CommissionCibleType::CODE_PROPRIETAIRE;
            $cibles[] = CommissionCibleType::CODE_EQUIPE_LIVRAISON;
        }
        // Site : cible directe supplémentaire, ancrée sur le site porté par le contexte (site de
        // l'opération pour une vente, site source explicite pour un transfert) — s'applique dès
        // qu'un site est présent (décision produit 2026-08-21 : jamais limité aux dépôts, jamais
        // conditionné à un gérant/une fonction/un rôle). Un site absent laisse simplement la cible
        // hors de $cibles : aucune tentative, aucune erreur — mirroring le comportement "pas de
        // règle configurée = pas de cible" déjà appliqué partout ailleurs.
        if ($ctx->site) {
            $cibles[] = CommissionCibleType::CODE_SITE;
        }
        // Consultant : toujours candidate, contrairement à Site — elle ne dépend d'aucune donnée
        // de l'opération, seulement d'une désignation au niveau organisation (cf.
        // CommissionConsultantAffectation). Une organisation qui n'a jamais configuré de barème
        // consultant ne verra jamais cette cible produire de contribution (absence de règle = 0,
        // décision AMOA #4) : aucun impact pour les organisations existantes. Indépendante du
        // véhicule (cf. décision produit du 05/09/2026, Grossiste + Enlèvement).
        $cibles[] = CommissionCibleType::CODE_CONSULTANT;

        /** @var array<string, array<int, array{ligne: Model, montant: float, regle: CommissionRegle}>> $lignesParCible */
        $lignesParCible = [];

        foreach ($lignes as $ligne) {
            $variante = $ligne->variante;
            $produit = $variante?->produit;
            $categorie = $produit?->categorie;
            $quantite = (float) $ligne->{$ctx->quantiteField};

            foreach ($cibles as $cibleCode) {
                $regle = CommissionRegleResolver::resolve(
                    $ctx->organizationId,
                    $processusBareme->id,
                    $cibleCode,
                    $variante?->id,
                    $produit?->id,
                    $categorie?->id,
                    $earnedAt,
                    $vehicule?->type_vehicule_id,
                );

                // Absence de règle = 0 pour cette cible sur cette ligne, jamais une
                // erreur (décision AMOA #4) — on passe simplement à la ligne suivante.
                if (! $regle || $regle->unite_calcul !== CommissionUniteCalcul::PAR_UNITE_VENDUE) {
                    continue;
                }

                $montantLigne = round($quantite * (float) $regle->montant, 2);

                $lignesParCible[$cibleCode][] = [
                    'ligne' => $ligne,
                    'variante' => $variante,
                    'categorie' => $categorie,
                    'quantite' => $quantite,
                    'montant' => $montantLigne,
                    'regle' => $regle,
                ];
            }
        }

        // Indépendance des cibles (chantier 2A, 05/09/2026 — révise l'ancienne décision AMOA #4
        // "tout-ou-rien", cf. §D de la conception cible) : les erreurs collectées ci-dessous ne
        // bloquent plus la persistance des cibles correctement résolues, cf. plus bas.
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
                // groupe : le partage Livreur est défini PAR CATÉGORIE (ET PAR PROCESSUS), en
                // montants GNF entiers fixes (equipe_livraison_partages_categorie), jamais un
                // pourcentage.
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
                    // produit) — cf. CommissionPartageLivraisonCategorieChecker, source unique
                    // partagée avec EquipeLivraisonController::validatePartagesCategorie et les
                    // garde-fous préventifs à la création (CommandeVenteController,
                    // TransfertLogistiqueController), pour garantir que "enveloppe" et "partage
                    // manquant" signifient toujours la même chose partout. Une éventuelle règle
                    // plus spécifique (variante/produit) sur une ligne de cette catégorie n'est
                    // donc pas prise en compte ici, contrairement aux autres cibles — limite
                    // assumée, cohérente avec le fait que le partage Livreur n'existe jamais à un
                    // grain plus fin.
                    $enveloppeUnitaire = CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                        $ctx->organizationId,
                        $processusBareme->id,
                        $categorieId,
                        $vehicule->type_vehicule_id,
                        $earnedAt,
                    );

                    // Barème Livreur configuré à 0 pour cette catégorie : valeur métier valide
                    // ("aucune commission à distribuer"), jamais un partage à exiger ni une
                    // erreur — rien à répartir, on passe à la suivante.
                    if ($enveloppeUnitaire <= 0) {
                        continue;
                    }

                    $quantiteCategorie = (int) $contribsCategorie->sum('quantite');

                    $partages = CommissionPartageLivraisonCategorieChecker::partagesActifs(
                        $processusBareme->id,
                        $vehicule->equipe->id,
                        $categorieId,
                        $earnedAt,
                    );

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
                $site = $ctx->site;

                // Bénéficiaire = le Site lui-même, directement — jamais un gérant, un employé, ou
                // un CommissionGroupe. Aucune vérification de gérant/fonction/rôle/statut/compte
                // utilisateur (décision produit 2026-08-21) : mode DIRECT au même titre que
                // CODE_PROPRIETAIRE ci-dessus, pas de répartition à calculer.
                if (! $site) {
                    $erreurs[] = "Cible {$cibleCode} : opération sans site.";

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
                    $ctx->organizationId,
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
                        ->where('organization_id', $ctx->organizationId)
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

        // Persistance de toutes les cibles correctement résolues, qu'il y ait ou non des
        // $erreurs par ailleurs (chantier 2A) — executerAvecTentative() décide du statut
        // SUCCES/PARTIEL/ERREUR à partir du $erreurs retourné plus bas, jamais d'un rollback ici.
        foreach ($enveloppesACreer as $cibleCode => $e) {
            $enveloppe = CommissionEnveloppe::create([
                'organization_id' => $ctx->organizationId,
                'source_type' => $ctx->sourceType,
                'source_id' => $ctx->sourceId,
                'processus_id' => $processusIdentite->id,
                'cible_type' => $e['cible_type'] ?? $cibleCode,
                'cible_id' => $e['cible_id'],
                'montant_total' => $e['montant'],
                'earned_at' => $earnedAt,
                'statut' => StatutCommission::CREEE->value,
            ]);

            foreach ($e['contributions'] as $c) {
                CommissionEnveloppeLigne::create([
                    'enveloppe_id' => $enveloppe->id,
                    'source_ligne_type' => $ctx->sourceLigneType,
                    'source_ligne_id' => $c['ligne']->id,
                    'variante_id' => $c['variante']?->id,
                    'categorie_id_snapshot' => $c['categorie']?->id,
                    'commission_regle_id' => $c['regle']->id,
                    'quantite' => $c['quantite'],
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

        return $erreurs;
    }
}
