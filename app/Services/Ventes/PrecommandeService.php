<?php

namespace App\Services\Ventes;

use App\Enums\AuditEvent;
use App\Enums\ModeConfirmationAnnulationExceptionnelle;
use App\Enums\ModePaiement;
use App\Enums\ModeTarification;
use App\Enums\OtpPurpose;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Mail\PrecommandeAnnulationCodeMail;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CompteTresorerie;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\RemboursementVente;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\CommissionTriggerService;
use App\Services\Comptabilite\VenteComptabilisationService;
use App\Services\OtpService;
use App\Services\StockReservationService;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'une précommande après sa création (ADR 0019, docs/precommandes.md § 6 à § 9) :
 * préparation, retrait, activation de la facture à la remise (statut depuis les acomptes, imputation
 * 419100 → 411000, commission, cashback, clôture), remboursement d'un client et annulation. Chaque
 * opération est atomique : tout réussit ou rien n'est enregistré.
 */
class PrecommandeService
{
    public const MOTIF_LONGUEUR_MIN = 10;

    public const MESSAGE_SANS_CAISSE = "Vous ne disposez pas d'une caisse active dans l'agence de cette précommande : impossible de rembourser en espèces.";

    public const MESSAGE_MOYEN_INDISPONIBLE = "Ce moyen de paiement n'est pas disponible dans l'agence de cette précommande : aucun support de trésorerie actif ne peut effectuer ce remboursement.";

    public function __construct(
        private readonly VenteComptabilisationService $comptabilite,
        private readonly MoyensEncaissementResolver $moyens,
        private readonly CaisseAgentResolver $caisses,
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly AuditLogService $audit,
        private readonly OtpService $otp,
    ) {}

    // ── Préparation ──────────────────────────────────────────────────────────

    /** RESERVEE → A_PREPARER. */
    public function lancerPreparation(CommandeVente $commande): void
    {
        $this->exigerStatut($commande, [StatutCommandeVente::RESERVEE], 'Seule une précommande réservée peut passer en préparation.');

        $commande->update([
            'statut' => StatutCommandeVente::A_PREPARER,
            'preparation_lancee_at' => now(),
        ]);

        CommandeVenteActiviteService::log($commande, 'preparation_lancee');
    }

    /**
     * A_PREPARER → PREPAREE (retrait) ou A_CHARGER (livraison, véhicule fixé à la création).
     * Quantité préparée ≤ demandée par ligne ; la réservation est ramenée à la quantité préparée (le
     * reste redevient disponible) et le montant recalculé sur ce qui est préparé — un acompte
     * devenu supérieur au nouveau total fait apparaître un trop-perçu à rembourser.
     *
     * @param  array<string, int>  $quantites  quantité préparée par ligne (id => quantité)
     */
    public function validerPreparation(CommandeVente $commande, array $quantites): void
    {
        $this->exigerStatut($commande, [StatutCommandeVente::A_PREPARER], 'La préparation doit être lancée avant d\'être validée.');

        DB::transaction(function () use ($commande, $quantites) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $commande->load('lignes.variante.produit.produitType');

            $this->appliquerQuantites($commande, $quantites, 'quantite_preparee', fn (CommandeVenteLigne $l) => $l->quantite_demandee, 'demandée');

            foreach ($commande->lignes as $ligne) {
                if ($ligne->variante?->produit?->produitType?->gere_stock) {
                    StockReservationService::reduire(CommandeVenteLigne::class, $ligne->id, $commande->site_id, $commande->organization_id, (int) $ligne->quantite_preparee);
                }
            }

            CommandeVenteService::recalculerTotaux($commande);

            $livraison = $commande->vehicule_id !== null;
            $commande->update([
                'statut' => $livraison ? StatutCommandeVente::A_CHARGER : StatutCommandeVente::PREPAREE,
                'preparee_at' => now(),
                'a_charger_at' => $livraison ? now() : $commande->a_charger_at,
            ]);

            CommandeVenteActiviteService::log($commande, 'preparation_validee', [
                'quantites' => $commande->lignes->mapWithKeys(fn ($l) => [$l->id => $l->quantite_preparee])->all(),
            ]);
        });
    }

    // ── Retrait ──────────────────────────────────────────────────────────────

    /**
     * PREPAREE → FACTURATION : le client repart avec la marchandise. Quantité remise ≤ préparée par
     * ligne, portée par `quantite_chargee` (quantité physiquement sortie du stock, même sens qu'un
     * client Externe qui charge lui-même) ; réservation consommée, sortie de stock, montant recalculé
     * sur ce qui est remis, puis activation de la facture (cf. activerFacture()).
     *
     * @param  array<string, int>  $quantites  quantité remise par ligne (id => quantité)
     */
    public function validerRetrait(CommandeVente $commande, array $quantites): void
    {
        $this->exigerStatut($commande, [StatutCommandeVente::PREPAREE], 'Seule une précommande préparée peut être retirée.');
        abort_if($commande->vehicule_id !== null, 422, 'Une précommande en livraison passe par le chargement, pas par le retrait.');

        DB::transaction(function () use ($commande, $quantites) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $commande->load('lignes');

            $this->appliquerQuantites($commande, $quantites, 'quantite_chargee', fn (CommandeVenteLigne $l) => (int) $l->quantite_preparee, 'préparée');

            CommandeVenteService::recalculerTotaux($commande);
            CommandeVenteService::decrementerStock($commande);

            $commande->update([
                'statut' => StatutCommandeVente::FACTURATION,
                'remise_at' => now(),
            ]);

            CommandeVenteActiviteService::log($commande, 'retrait_valide', [
                'quantites' => $commande->lignes->mapWithKeys(fn ($l) => [$l->id => $l->quantite_chargee])->all(),
            ]);

            $this->activerFacture($commande);

            // Même déclencheur qu'une vente directe (aucune étape de chargement à déclencher) — cf.
            // CommandeVenteService::creerFactureDirecte().
            CommissionTriggerService::onVenteDirecteFacturee($commande->fresh());
        });
    }

    // ── Remise : activation de la facture ────────────────────────────────────

    /**
     * La vente est réalisée (retrait validé ou chargement validé) : la facture quitte « Créée ».
     * Vente constatée (411/701), acomptes encore détenus imputés (419100 → 411000), statut calculé
     * depuis l'encaissé net (Impayée / Partiel / Payée — jamais « Impayée » d'office, contrairement à
     * une vente sans acompte), puis cashback et clôture si la facture est payée — pour une livraison,
     * différés à la livraison définitive (D13, D14). Appelé dans la transaction de la remise ;
     * idempotent (sans effet sur une facture déjà activée).
     */
    public function activerFacture(CommandeVente $commande): void
    {
        $commande->load('facture');
        $facture = $commande->facture;

        if (! $facture || $facture->statut_facture !== StatutFactureVente::CREEE) {
            return;
        }

        if ($commande->remise_at === null) {
            $commande->update(['remise_at' => now()]);
        }

        $acomptes = $facture->encaisseNet();

        // Statut provisoire hors « Créée » pour que recalculStatut() — seul point qui déclenche la
        // commission sous « facture encaissée » — calcule le vrai statut depuis les acomptes.
        $facture->update(['statut_facture' => StatutFactureVente::IMPAYEE]);
        CommandeVenteService::comptabiliserVenteFacturee($facture);
        $this->comptabilite->comptabiliserImputationAcomptes($facture, $acomptes);

        if ((float) $facture->montant_net <= 0) {
            $facture->update(['statut_facture' => StatutFactureVente::PAYEE]);
        } else {
            $facture->recalculStatut();
        }

        // Une livraison reste « En livraison » même soldée par ses acomptes : le chargement ne vaut
        // pas livraison (D13) — cf. confirmerLivraison(). La cascade est alors sans effet (D14).
        if ($facture->refresh()->isPayee()) {
            FacturePayeeCascade::apresPassageEnPayee($commande->fresh());
        }

        $commande->fresh()->cloturerSiComplete();
    }

    // ── Livraison ────────────────────────────────────────────────────────────

    /**
     * LIVRAISON_EN_COURS → LIVREE (décision D13) : la marchandise a bien été remise au client. Seul
     * moyen de confirmer une livraison soldée par ses acomptes — aucun encaissement ne viendra le
     * faire. Jamais pour une commande à réception explicite (distribution, Grossiste livré), confirmée
     * par la validation de réception.
     */
    public function confirmerLivraison(CommandeVente $commande): void
    {
        $this->exigerStatut($commande, [StatutCommandeVente::LIVRAISON_EN_COURS], 'Seule une précommande en livraison peut être confirmée livrée.');
        abort_if($commande->requiertReceptionExplicite(), 422, 'Cette livraison se confirme par la validation de réception.');

        DB::transaction(function () use ($commande) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $this->exigerStatut($commande, [StatutCommandeVente::LIVRAISON_EN_COURS], 'Seule une précommande en livraison peut être confirmée livrée.');

            CommandeVenteService::passerEnLivree($commande);
            CommandeVenteActiviteService::log($commande, 'livree');

            $this->apresLivraisonDefinitive($commande);
        });
    }

    /**
     * Livraison définitive d'une précommande (confirmée, ou réception validée) : statut de la facture
     * recalculé depuis l'encaissé net, cashback si elle est payée — différé jusqu'ici (D14) — et
     * clôture si tout est réglé.
     */
    public function apresLivraisonDefinitive(CommandeVente $commande): void
    {
        $facture = $commande->fresh('facture')->facture;
        if (! $facture || $facture->isCreee() || $facture->isAnnulee()) {
            return;
        }

        $facture->recalculStatut();
        if ($facture->isPayee()) {
            FacturePayeeCascade::apresPassageEnPayee($commande->fresh());
        }

        $commande->fresh()->cloturerSiComplete();
    }

    /**
     * Après un retour de livraison ou un écart de réception (ADR 0019, C4 / C5) : le montant facturé
     * a baissé, le statut de la facture est recalculé depuis l'encaissé net — « Partiel » peut
     * devenir « Payée » — et l'excédent éventuel apparaît comme trop-perçu (FactureVente::tropPercu()).
     */
    public static function apresReductionMontant(CommandeVente $commande): void
    {
        $facture = $commande->fresh('facture')->facture;
        if ($commande->est_precommande && $facture && ! $facture->isCreee() && ! $facture->isAnnulee()) {
            $facture->recalculStatut();
        }
    }

    // ── Remboursement ────────────────────────────────────────────────────────

    /**
     * Rend à un client l'argent qu'on lui doit : trop-perçu (motif `trop_percu`, plafonné au
     * trop-perçu) ou acomptes d'une précommande annulée (motif `annulation`, plafonné à l'encaissé
     * net). Sortie réelle depuis un support de l'agence de la commande (espèces : caisse dédiée du
     * payeur), solde vérifié sous verrou, écriture bloquante — ADR 0009.
     *
     * @param  array{montant: float|int|string, mode_paiement: string, compte_tresorerie_id?: ?string, reference_paiement?: ?string, note?: ?string}  $paiement
     */
    public function rembourser(CommandeVente $commande, User $payeur, array $paiement, string $motif = RemboursementVente::MOTIF_TROP_PERCU): RemboursementVente
    {
        abort_if(! $commande->est_precommande, 422, 'Seule une précommande donne lieu à un remboursement.');

        $support = $this->supportRemboursement($commande, $payeur, $paiement['mode_paiement'], $paiement['compte_tresorerie_id'] ?? null);
        $montant = round((float) $paiement['montant'], 2);

        if (in_array($paiement['mode_paiement'], [ModePaiement::MOBILE_MONEY->value, ModePaiement::VIREMENT->value], true)
            && blank($paiement['reference_paiement'] ?? null)) {
            throw ValidationException::withMessages([
                'reference_paiement' => 'La référence du remboursement est obligatoire pour ce mode de paiement.',
            ]);
        }

        return DB::transaction(function () use ($commande, $payeur, $paiement, $motif, $support, $montant) {
            $facture = FactureVente::whereKey($commande->facture?->id)->lockForUpdate()->firstOrFail();
            $plafond = $motif === RemboursementVente::MOTIF_ANNULATION ? $facture->encaisseNet() : $facture->tropPercu();

            if ($montant <= 0 || $montant > $plafond + 0.004) {
                throw ValidationException::withMessages([
                    'montant' => 'Le remboursement ne peut pas dépasser '.self::gnf($plafond).'.',
                ]);
            }

            $this->disponibilite->garantirSoldeSuffisant($support->id, $montant, now(), 'un remboursement');

            $remboursement = RemboursementVente::create([
                'organization_id' => $commande->organization_id,
                'site_id' => $support->site_id,
                'commande_vente_id' => $commande->id,
                'facture_vente_id' => $facture->id,
                'motif' => $motif,
                'montant' => $montant,
                'mode_paiement' => $paiement['mode_paiement'],
                'operateur_mobile_money' => $support->operateur_mobile_money?->value,
                'compte_tresorerie_id' => $support->id,
                'reference_paiement' => $paiement['reference_paiement'] ?? null,
                'date_remboursement' => now()->toDateString(),
                'note' => $paiement['note'] ?? null,
                'created_by' => $payeur->id,
            ]);

            $facture->update(['montant_rembourse' => round($facture->montantRembourse() + $montant, 2)]);
            $this->comptabilite->comptabiliserRemboursement($remboursement, $commande->remise_at !== null);

            $this->audit->record($commande, AuditEvent::PAID, $payeur, null, [
                'remboursement' => true,
                'motif' => $motif,
                'montant' => $montant,
                'mode_paiement' => $paiement['mode_paiement'],
                'compte_tresorerie_id' => $support->id,
            ]);
            CommandeVenteActiviteService::log($commande, 'remboursement', ['montant' => $montant, 'motif' => $motif]);

            $facture->recalculStatut();
            $commande->fresh()->cloturerSiComplete();

            return $remboursement;
        });
    }

    // ── Annulation ───────────────────────────────────────────────────────────

    /**
     * La précommande peut-elle encore être annulée, et par quelle procédure ? Avant préparation
     * (Réservée) : procédure simple. En préparation, préparée ou à charger : procédure renforcée
     * (décision D1). Jamais une fois le chargement démarré ni après la remise.
     */
    public static function annulationRenforcee(CommandeVente $commande): bool
    {
        return $commande->statut !== StatutCommandeVente::RESERVEE;
    }

    public static function estAnnulable(CommandeVente $commande): bool
    {
        return $commande->est_precommande
            && $commande->remise_at === null
            && in_array($commande->statut, [
                StatutCommandeVente::RESERVEE,
                StatutCommandeVente::A_PREPARER,
                StatutCommandeVente::PREPAREE,
                StatutCommandeVente::A_CHARGER,
            ], true);
    }

    /**
     * Envoie à l'utilisateur authentifié le code de confirmation d'une annulation renforcée, quand
     * l'organisation l'exige (même réglage que l'annulation exceptionnelle, ADR 0004 ; OtpService :
     * 10 min, usage unique, tentatives limitées).
     *
     * @return array{destination: string, expire_minutes: int}
     */
    public function demanderCodeAnnulation(CommandeVente $commande, User $user): array
    {
        abort_if(! self::estAnnulable($commande) || ! self::annulationRenforcee($commande), 422, 'Cette précommande ne demande pas de code de confirmation.');

        if (Parametre::getModeConfirmationAnnulationExceptionnelle($commande->organization_id) !== ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE) {
            throw ValidationException::withMessages(['code' => 'Votre organisation ne demande pas de code : confirmez directement.']);
        }

        $email = $user->email;
        if (! $email) {
            throw ValidationException::withMessages(['code' => "Votre compte n'a pas d'adresse e-mail : le code de confirmation ne peut pas être envoyé."]);
        }

        $contexte = self::contexteCode($commande);
        $attente = $this->otp->resendWaitSeconds($email, $contexte);
        if ($attente > 0) {
            throw ValidationException::withMessages(['code' => "Un code vient d'être demandé. Patientez {$attente} secondes avant d'en demander un nouveau."]);
        }

        $code = $this->otp->generate($email, OtpPurpose::ANNULATION_PRECOMMANDE, $contexte);

        try {
            Mail::to($email)->send(new PrecommandeAnnulationCodeMail($code, (string) $commande->reference, $this->otp->ttlMinutes()));
        } catch (\Throwable $e) {
            $this->otp->clear($email, OtpPurpose::ANNULATION_PRECOMMANDE, $contexte);
            Log::error('precommande.annulation.envoi_code_echoue', ['commande_id' => $commande->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['code' => "L'e-mail contenant le code n'a pas pu être envoyé. Réessayez plus tard."]);
        }

        return ['destination' => OtpService::mask($email), 'expire_minutes' => $this->otp->ttlMinutes()];
    }

    /**
     * Annule la précommande : rembourse d'abord tout ce que le client a versé (obligatoire, montant
     * exact attendu par l'écran — s'il a changé, refus), puis libère la réservation et annule la
     * facture. Distincte de l'annulation exceptionnelle (ADR 0004, ventes fictives sans
     * remboursement) : la vente réelle est simplement annulée, statut `annulee`.
     *
     * @param  array{montant?: float|int|string|null, mode_paiement?: ?string, compte_tresorerie_id?: ?string, reference_paiement?: ?string}  $paiement
     */
    public function annuler(CommandeVente $commande, User $user, string $motif, array $paiement = [], ?string $code = null): void
    {
        abort_if(! self::estAnnulable($commande), 422, 'Cette précommande ne peut plus être annulée.');

        if (mb_strlen(trim($motif)) < self::MOTIF_LONGUEUR_MIN) {
            throw ValidationException::withMessages(['motif' => 'Indiquez le motif de l\'annulation (au moins '.self::MOTIF_LONGUEUR_MIN.' caractères).']);
        }

        if (self::annulationRenforcee($commande)) {
            $this->verifierConfirmationRenforcee($commande, $user, $code);
        }

        DB::transaction(function () use ($commande, $user, $motif, $paiement) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            abort_if(! self::estAnnulable($commande), 422, 'Cette précommande ne peut plus être annulée.');

            $aRembourser = $commande->facture?->encaisseNet() ?? 0.0;
            if ($aRembourser > 0) {
                $annonce = round((float) ($paiement['montant'] ?? 0), 2);
                if (abs($annonce - $aRembourser) > 0.004 || empty($paiement['mode_paiement'])) {
                    throw ValidationException::withMessages([
                        'montant' => 'Le client doit être remboursé de '.self::gnf($aRembourser).' avant l\'annulation : rouvrez l\'annulation pour vérifier le montant.',
                    ]);
                }

                $this->rembourser($commande, $user, [...$paiement, 'montant' => $aRembourser], RemboursementVente::MOTIF_ANNULATION);
            }

            $statutAvant = $commande->statut->value;
            CommandeVenteService::annulerPrecommande($commande->fresh(), trim($motif));

            $this->audit->record($commande, AuditEvent::CANCELLED, $user, ['statut' => $statutAvant], [
                'statut' => StatutCommandeVente::ANNULEE->value,
                'motif' => trim($motif),
                'rembourse' => $aRembourser,
            ]);
            CommandeVenteActiviteService::log($commande, 'precommande_annulee', ['motif' => trim($motif), 'rembourse' => $aRembourser]);
        });
    }

    // ── Données d'écran ──────────────────────────────────────────────────────

    /**
     * Montants affichés sur la fiche d'une précommande.
     *
     * @return array{total: float, acomptes: float, rembourse: float, encaisse_net: float, reste: float, trop_percu: float}
     */
    public static function montants(CommandeVente $commande): array
    {
        $facture = $commande->facture;
        $acomptes = $facture ? round((float) $facture->encaissements->where('est_acompte', true)->sum('montant'), 2) : 0.0;

        return [
            'total' => round((float) $commande->total_commande, 2),
            'acomptes' => $acomptes,
            'rembourse' => $facture?->montantRembourse() ?? 0.0,
            'encaisse_net' => $facture?->encaisseNet() ?? 0.0,
            'reste' => $facture && ! $facture->isAnnulee() ? $facture->montant_restant : 0.0,
            'trop_percu' => $facture?->tropPercu() ?? 0.0,
        ];
    }

    // ── Interne ──────────────────────────────────────────────────────────────

    /**
     * @param  list<StatutCommandeVente>  $statuts
     */
    private function exigerStatut(CommandeVente $commande, array $statuts, string $message): void
    {
        abort_if(! $commande->est_precommande, 422, "Cette commande n'est pas une précommande.");
        abort_if(! in_array($commande->statut, $statuts, true), 422, $message);
    }

    /**
     * Enregistre une quantité par ligne dans `$colonne` (0 ≤ quantité ≤ plafond) et recalcule le
     * total de la ligne au prix figé à la création. Au moins une quantité positive : une précommande
     * dont rien ne reste s'annule (avec remboursement), elle ne se « remet » pas à zéro.
     *
     * @param  array<string, int>  $quantites
     * @param  callable(CommandeVenteLigne): int  $plafond
     */
    private function appliquerQuantites(CommandeVente $commande, array $quantites, string $colonne, callable $plafond, string $libellePlafond): void
    {
        $erreurs = [];
        foreach ($commande->lignes as $ligne) {
            $quantite = $quantites[$ligne->id] ?? null;
            $max = $plafond($ligne);

            if ($quantite === null || ! is_numeric($quantite) || (int) $quantite != $quantite || $quantite < 0 || $quantite > $max) {
                $erreurs[] = "« {$ligne->libelle_snapshot} » : quantité entre 0 et {$max} (quantité {$libellePlafond}).";
            }
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages(['lignes' => $erreurs]);
        }

        if (collect($commande->lignes)->every(fn ($l) => (int) $quantites[$l->id] === 0)) {
            throw ValidationException::withMessages([
                'lignes' => 'Aucune quantité : si plus rien n\'est à remettre au client, annulez la précommande (ses acomptes lui seront remboursés).',
            ]);
        }

        foreach ($commande->lignes as $ligne) {
            $quantite = (int) $quantites[$ligne->id];
            $prix = $commande->mode_tarification_snapshot === ModeTarification::PRIX_USINE
                ? (float) $ligne->prix_usine_snapshot
                : (float) $ligne->prix_vente_snapshot;

            $ligne->update([$colonne => $quantite, 'total_ligne' => $quantite * $prix]);
        }
    }

    /**
     * Support d'où sort un remboursement : caisse dédiée active du payeur dans l'agence de la commande
     * pour les espèces, sinon un support actif de cette agence pour ce mode — contrôle serveur,
     * indépendant de ce que l'écran a proposé (même règle que DecaissementFicheResolver).
     */
    private function supportRemboursement(CommandeVente $commande, User $payeur, string $modePaiement, ?string $compteTresorerieId): CompteTresorerie
    {
        if ($modePaiement === ModePaiement::ESPECES->value) {
            $caisse = $this->caisses->caisseActive($commande->organization_id, (string) $payeur->id, $commande->site_id);
            if (! $caisse) {
                throw ValidationException::withMessages(['mode_paiement' => self::MESSAGE_SANS_CAISSE]);
            }

            return $caisse;
        }

        $support = $this->moyens->supportPour($commande->organization_id, $commande->site_id, $compteTresorerieId, $modePaiement);
        if (! $support) {
            throw ValidationException::withMessages(['compte_tresorerie_id' => self::MESSAGE_MOYEN_INDISPONIBLE]);
        }

        return $support;
    }

    private function verifierConfirmationRenforcee(CommandeVente $commande, User $user, ?string $code): void
    {
        if (Parametre::getModeConfirmationAnnulationExceptionnelle($commande->organization_id) !== ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE) {
            return;
        }

        $email = (string) $user->email;
        $contexte = self::contexteCode($commande);

        if ($this->otp->tooManyAttempts($email, OtpPurpose::ANNULATION_PRECOMMANDE, $contexte)) {
            throw ValidationException::withMessages(['code' => 'Trop de tentatives : demandez un nouveau code.']);
        }

        if (blank($code) || ! $this->otp->verify($email, (string) $code, OtpPurpose::ANNULATION_PRECOMMANDE, $contexte)) {
            throw ValidationException::withMessages(['code' => 'Code de confirmation invalide ou expiré.']);
        }
    }

    private static function contexteCode(CommandeVente $commande): string
    {
        return 'precommande:'.$commande->id;
    }

    private static function gnf(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' GNF';
    }
}
