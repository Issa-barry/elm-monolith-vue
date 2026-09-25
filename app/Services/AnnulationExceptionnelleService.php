<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\EvenementComptable;
use App\Enums\ModeConfirmationAnnulationExceptionnelle;
use App\Enums\OtpPurpose;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Mail\AnnulationExceptionnelleCodeMail;
use App\Models\AnnulationExceptionnelle;
use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\MouvementStock;
use App\Models\Parametre;
use App\Models\User;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Annulation exceptionnelle d'une commande de vente saisie par erreur (ex : un employé qui se
 * croyait sur l'environnement de formation) — décision produit du 24/09/2026, cf.
 * docs/annulation-exceptionnelle.md et docs/adr/0004. Contrairement à l'annulation normale
 * (CommandeVenteService::annuler(), jamais après le départ du véhicule ni après un encaissement),
 * elle défait une commande chargée, livrée, facturée et même encaissée :
 *
 *  - encaissements supprimés, leur écriture comptable CONTREPASSÉE (jamais effacée) — ce qui
 *    retire l'argent fictif de la caisse qui l'avait reçu ;
 *  - facture annulée, écriture de vente contrepassée ;
 *  - sorties de stock contre-passées (la marchandise n'a jamais réellement bougé) ;
 *  - commissions non soldées annulées, cashback encore en attente retiré.
 *
 * Réservée à la permission `ventes.annuler_exceptionnel`. Le niveau de confirmation est un
 * paramètre d'organisation lu ICI, côté serveur, à chaque étape (jamais fourni par le frontend) —
 * cf. ModeConfirmationAnnulationExceptionnelle, défaut EMAIL_CODE :
 *
 *  - EMAIL_CODE : code à usage unique envoyé par e-mail à l'utilisateur authentifié. Il prouve que
 *    c'est bien lui qui confirme (réauthentification) — jamais l'accord d'une seconde personne. Le
 *    code est lié à la commande et, par la demande mémorisée côté serveur, au motif saisi ;
 *  - SIMPLE : confirmation directe, sans code.
 *
 * Dans les deux modes, l'empreinte des données présentées dans le récapitulatif est exigée : si
 * elles changent entre l'affichage et la confirmation, l'opération est refusée. Tous les garde-fous sont revérifiés
 * sous verrou au moment d'exécuter — le frontend n'est jamais la preuve de ce qui a été validé.
 */
class AnnulationExceptionnelleService
{
    /** Un motif explicite est exigé : il est la seule justification écrite de l'opération. */
    public const MOTIF_LONGUEUR_MIN = 10;

    private const MSG_DONNEES_MODIFIEES = 'Les données de cette commande ont changé depuis l\'affichage du récapitulatif. Rouvrez l\'annulation exceptionnelle pour vérifier les nouveaux montants.';

    private const MSG_NOUVEAU_CODE = 'Aucun code valide pour cette annulation : demandez un nouveau code.';

    public function __construct(
        private readonly OtpService $otp,
        private readonly EcritureComptableService $ecritures,
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Raison pour laquelle l'annulation exceptionnelle n'est pas proposée pour cette commande
     * (état de la commande uniquement), ou null si elle l'est. Une commande encore annulable
     * normalement (avant chargement, rien d'encaissé) garde l'annulation normale.
     */
    public static function raisonStatutNonEligible(CommandeVente $commande): ?string
    {
        if ($commande->isAnnulee() || $commande->isAnnuleeErreurSaisie()) {
            return 'Cette commande est déjà annulée.';
        }

        if ($commande->isRetournee()) {
            return 'Cette commande est déjà retournée : sa marchandise est revenue et sa facture est annulée.';
        }

        $commande->loadMissing('facture');
        if ($commande->statut->isAnnulable() && (float) ($commande->facture?->montant_encaisse ?? 0) <= 0) {
            return 'Cette commande peut encore être annulée normalement : utilisez « Annuler ».';
        }

        return null;
    }

    /**
     * Tout ce que l'annulation va défaire, les raisons de refus éventuelles et l'empreinte de ces
     * données. Lecture seule.
     *
     * @return array<string, mixed>
     */
    public function recapitulatif(CommandeVente $commande): array
    {
        $commande->load([
            'lignes.variante.produit',
            'facture.encaissements.creator',
            'commissions.parts',
            'client',
        ]);

        $facture = $commande->facture;
        $encaissements = $facture?->encaissements ?? collect();

        $lignesEncaissement = $encaissements->map(function (EncaissementVente $e) use ($commande) {
            $support = $this->supportEncaissement($e, $commande);

            return [
                'id' => $e->id,
                'montant' => (float) $e->montant,
                'mode_paiement' => $e->mode_paiement?->value,
                'mode_paiement_label' => $e->mode_paiement?->label(),
                'date' => $e->date_encaissement?->format('d/m/Y'),
                'auteur' => $e->creator?->name,
                'caisse' => $support?->libelle,
                'caisse_id' => $support?->id,
                'caisse_dediee' => (bool) $support?->isDediee(),
            ];
        })->values();

        $commissions = $commande->commissions->filter(fn ($c) => $c->statut !== StatutCommission::ANNULEE);
        $cashback = $this->gainCashback($commande);

        $stock = $commande->lignes->map(fn (CommandeVenteLigne $ligne) => [
            'id' => $ligne->id,
            'produit' => $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? 'Produit',
            'quantite_commandee' => (int) $ligne->quantite_demandee,
            'quantite_chargee' => $ligne->quantite_chargee !== null ? (int) $ligne->quantite_chargee : null,
            'quantite_livree' => $ligne->quantite_livree !== null ? (int) $ligne->quantite_livree : null,
            'quantite_retournee' => (int) $ligne->quantite_retournee,
            'quantite_a_reintegrer' => (int) MouvementStock::query()
                ->where('source_type', CommandeVenteLigne::class)
                ->where('source_id', $ligne->id)
                ->where('site_id', $commande->site_id)
                ->where('type', 'sortie')
                ->whereNull('annule_par_id')
                ->sum('quantite'),
        ])->values();

        $recap = [
            'commande' => [
                'id' => $commande->id,
                'reference' => $commande->reference,
                'statut' => $commande->statut->value,
                'statut_label' => $commande->statut->label(),
                'montant' => (float) $commande->total_commande,
                'client' => $commande->client?->nom_complet,
            ],
            'facture' => $facture ? [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'statut' => $facture->statut_facture?->value,
                'statut_label' => $facture->statut_label,
                'montant_net' => (float) $facture->montant_net,
                'montant_encaisse' => (float) $facture->montant_encaisse,
            ] : null,
            'encaissements' => $lignesEncaissement,
            'total_encaisse' => (float) $encaissements->sum('montant'),
            'commissions' => [
                'nombre' => $commissions->count(),
                'montant' => (float) $commissions->sum('montant_total'),
                'details' => $commissions->map(fn ($c) => [
                    'id' => $c->id,
                    'statut' => $c->statut?->value,
                    'montant' => (float) $c->montant_total,
                ])->values(),
            ],
            'cashback' => $cashback ? [
                'id' => $cashback->id,
                'montant' => (float) $cashback->montant,
                'statut' => $cashback->statut,
            ] : null,
            'stock' => $stock,
            'retours' => $commande->retours()->count(),
        ];

        $recap['empreinte'] = self::empreinte($recap);
        $recap['blocages'] = $this->blocages($commande, $recap);
        // Hors empreinte : c'est un réglage, pas une donnée de la commande. Relu à la confirmation.
        $recap['mode_confirmation'] = self::mode($commande)->value;

        return $recap;
    }

    /**
     * Génère le code, l'envoie à l'adresse de l'utilisateur authentifié et mémorise ce qu'il
     * confirme (empreinte + motif). Un nouveau code invalide le précédent.
     *
     * @return array{destination: string, expire_minutes: int, renvoi_dans: int}
     *
     * @throws ValidationException
     */
    public function demanderCode(CommandeVente $commande, User $user, string $motif, string $empreinte): array
    {
        $this->verifierOrganisation($commande, $user);

        if (self::mode($commande) === ModeConfirmationAnnulationExceptionnelle::SIMPLE) {
            throw ValidationException::withMessages([
                'code' => 'Le mode de confirmation de votre organisation ne demande pas de code : confirmez directement.',
            ]);
        }

        $this->verifierDonnees($this->recapitulatif($commande), $empreinte);

        $email = $user->email;
        if (! $email) {
            throw ValidationException::withMessages([
                'code' => 'Votre compte n\'a pas d\'adresse e-mail : le code de confirmation ne peut pas être envoyé.',
            ]);
        }

        $contexte = self::contexte($commande);
        $attente = $this->otp->resendWaitSeconds($email, $contexte);
        if ($attente > 0) {
            throw ValidationException::withMessages([
                'code' => "Un code vient d'être demandé. Patientez {$attente} secondes avant d'en demander un nouveau.",
            ]);
        }

        $code = $this->otp->generate($email, OtpPurpose::ANNULATION_EXCEPTIONNELLE, $contexte);

        try {
            Mail::to($email)->send(new AnnulationExceptionnelleCodeMail($code, (string) $commande->reference, $this->otp->ttlMinutes()));
        } catch (\Throwable $e) {
            $this->otp->clear($email, OtpPurpose::ANNULATION_EXCEPTIONNELLE, $contexte);
            Log::error('annulation_exceptionnelle.envoi_code_echoue', [
                'commande_id' => $commande->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'code' => 'L\'e-mail contenant le code n\'a pas pu être envoyé. Réessayez plus tard ou contactez l\'administrateur.',
            ]);
        }

        $destination = OtpService::mask($email);
        Cache::put(self::cleDemande($commande, $user), [
            'empreinte' => $empreinte,
            'motif' => self::empreinteMotif($motif),
            'destination' => $destination,
            'demande_at' => now()->toIso8601String(),
        ], now()->addMinutes($this->otp->ttlMinutes()));

        return [
            'destination' => $destination,
            'expire_minutes' => $this->otp->ttlMinutes(),
            'renvoi_dans' => $this->otp->resendCooldownSeconds(),
        ];
    }

    /**
     * Vérifie la confirmation exigée par le mode de l'organisation (code en EMAIL_CODE, rien de plus
     * en SIMPLE) puis exécute l'annulation et toutes ses régularisations dans une seule
     * transaction, après avoir revérifié sous verrou que les données n'ont pas changé.
     *
     * @throws ValidationException
     */
    public function confirmer(CommandeVente $commande, User $user, string $motif, string $empreinte, ?string $code = null): AnnulationExceptionnelle
    {
        $this->verifierOrganisation($commande, $user);

        $mode = self::mode($commande);
        $demande = [];

        if ($mode === ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE) {
            $demande = $this->verifierConfirmationParCode($commande, $user, $motif, $empreinte, $code);
        } else {
            $this->verifierDonnees($this->recapitulatif($commande), $empreinte);
        }

        return DB::transaction(function () use ($commande, $user, $motif, $empreinte, $demande, $mode) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $recap = $this->recapitulatif($commande);
            $this->verifierDonnees($recap, $empreinte);

            $statutAvant = $commande->statut->value;
            $regularisations = [];

            foreach ($commande->facture?->encaissements ?? [] as $encaissement) {
                $this->audit->record($commande, AuditEvent::ENCAISSEMENT_DELETED, $user, [
                    'montant' => (float) $encaissement->montant,
                    'mode_paiement' => $encaissement->mode_paiement?->value,
                    'date_encaissement' => $encaissement->date_encaissement?->toDateString(),
                ], null, ['annulation_exceptionnelle' => true]);

                // Le hook EncaissementVente::deleted recalcule la facture et contrepasse l'écriture
                // d'encaissement (jamais supprimée) : c'est ce qui retire l'argent de la caisse.
                $encaissement->delete();
            }
            if ($recap['encaissements']->isNotEmpty()) {
                $regularisations[] = [
                    'type' => 'encaissements_contrepasses',
                    'montant' => $recap['total_encaisse'],
                    'details' => $recap['encaissements']->all(),
                ];
            }

            if ($recap['cashback']) {
                $this->retirerCashback($commande);
                $regularisations[] = ['type' => 'cashback_retire', 'montant' => $recap['cashback']['montant']];
            }

            $motifComplet = 'Annulation exceptionnelle (erreur de saisie) : '.trim($motif);
            CommandeVenteService::annulerPourErreurSaisie($commande, $motifComplet);

            if ($recap['facture']) {
                $regularisations[] = ['type' => 'facture_annulee', 'facture' => $recap['facture']['reference'], 'montant' => $recap['facture']['montant_net']];
            }
            $lignesStock = $recap['stock']->filter(fn ($l) => $l['quantite_a_reintegrer'] > 0)->values();
            if ($lignesStock->isNotEmpty()) {
                $regularisations[] = ['type' => 'stock_reintegre', 'lignes' => $lignesStock->all()];
            }
            if ($recap['commissions']['nombre'] > 0) {
                $regularisations[] = ['type' => 'commissions_annulees', 'montant' => $recap['commissions']['montant'], 'details' => $recap['commissions']['details']->all()];
            }

            $trace = AnnulationExceptionnelle::create([
                'organization_id' => $commande->organization_id,
                'commande_vente_id' => $commande->id,
                'facture_vente_id' => $recap['facture']['id'] ?? null,
                'user_id' => $user->id,
                'motif' => trim($motif),
                'statut_avant' => $statutAvant,
                'empreinte' => $empreinte,
                'methode_confirmation' => $mode->value,
                'code_envoye_a' => $demande['destination'] ?? null,
                'code_demande_at' => isset($demande['demande_at']) ? Carbon::parse($demande['demande_at']) : null,
                'confirmee_at' => now(),
                'montant_commande' => $recap['commande']['montant'],
                'montant_facture' => $recap['facture']['montant_net'] ?? 0,
                'montant_encaisse' => $recap['total_encaisse'],
                'montant_commissions' => $recap['commissions']['montant'],
                'montant_cashback' => $recap['cashback']['montant'] ?? 0,
                'snapshot' => self::serialiser($recap),
                'regularisations' => $regularisations,
            ]);

            $this->audit->record(
                $commande,
                AuditEvent::CANCELLED,
                $user,
                ['statut' => $statutAvant, 'motif_annulation' => null],
                ['statut' => StatutCommandeVente::ANNULEE_ERREUR_SAISIE->value, 'motif_annulation' => $motifComplet],
                ['annulation_exceptionnelle_id' => $trace->id],
            );

            CommandeVenteActiviteService::log($commande, 'annulee_erreur_saisie', [
                'motif' => trim($motif),
                'montant_encaisse' => $recap['total_encaisse'],
            ]);

            return $trace;
        });
    }

    /**
     * Mode EMAIL_CODE : un code doit avoir été demandé pour ce motif et ces données, et être
     * valide. Renvoie la demande mémorisée (adresse masquée, date) pour la trace d'audit.
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function verifierConfirmationParCode(CommandeVente $commande, User $user, string $motif, string $empreinte, ?string $code): array
    {
        if ($code === null || $code === '') {
            throw ValidationException::withMessages(['code' => 'Saisissez le code reçu par e-mail.']);
        }

        $demande = Cache::get(self::cleDemande($commande, $user));
        $email = (string) $user->email;

        if (! is_array($demande) || $email === '') {
            throw ValidationException::withMessages(['code' => self::MSG_NOUVEAU_CODE]);
        }

        if (! hash_equals($demande['empreinte'], $empreinte) || ! hash_equals($demande['motif'], self::empreinteMotif($motif))) {
            throw ValidationException::withMessages([
                'code' => 'Ce code a été demandé pour un autre motif ou d\'autres montants : demandez un nouveau code.',
            ]);
        }

        $this->verifierDonnees($this->recapitulatif($commande), $empreinte);
        $this->verifierCode($email, $code, self::contexte($commande));
        Cache::forget(self::cleDemande($commande, $user));

        return $demande;
    }

    private static function mode(CommandeVente $commande): ModeConfirmationAnnulationExceptionnelle
    {
        return Parametre::getModeConfirmationAnnulationExceptionnelle($commande->organization_id);
    }

    // ── Garde-fous ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $recap
     * @return list<string>
     */
    private function blocages(CommandeVente $commande, array $recap): array
    {
        $blocages = [];

        if ($raison = self::raisonStatutNonEligible($commande)) {
            $blocages[] = $raison;
        }

        if ($recap['retours'] > 0) {
            $blocages[] = 'Un retour de livraison a déjà été enregistré sur cette commande : l\'annulation exceptionnelle n\'est pas possible dans ce cas.';
        }

        if (CommissionTriggerService::aDesCommissionsFigees($commande)) {
            $blocages[] = 'La commission de cette commande a déjà été validée, ajustée ou payée. Régularisez-la d\'abord.';
        }

        if ($recap['cashback'] && $recap['cashback']['statut'] !== CashbackTransaction::STATUT_EN_ATTENTE) {
            $blocages[] = 'Le cashback généré par cette commande a déjà été validé ou versé au client.';
        }

        // Espèces encore dans la caisse dédiée de l'agent : si elles en sont déjà sorties
        // (versement à l'agence), contrepasser l'encaissement rendrait sa caisse négative.
        $parCaisse = collect($recap['encaissements'])->filter(fn ($e) => $e['caisse_dediee'])->groupBy('caisse_id');
        foreach ($parCaisse as $caisseId => $lignes) {
            $caisse = CompteTresorerie::find($caisseId);
            $montant = (float) $lignes->sum('montant');
            $solde = $caisse ? $this->disponibilite->soldePourSupport($caisse) : 0.0;

            if ($solde + 0.005 < $montant) {
                $blocages[] = sprintf(
                    'Les espèces encaissées (%s GNF) ont déjà quitté la caisse « %s » (solde actuel : %s GNF). Annulez d\'abord le versement correspondant.',
                    number_format($montant, 0, ',', ' '),
                    $caisse?->libelle ?? '—',
                    number_format($solde, 0, ',', ' '),
                );
            }
        }

        return $blocages;
    }

    /**
     * @param  array<string, mixed>  $recap
     *
     * @throws ValidationException
     */
    private function verifierDonnees(array $recap, string $empreinte): void
    {
        if (! hash_equals($recap['empreinte'], $empreinte)) {
            throw ValidationException::withMessages(['empreinte' => self::MSG_DONNEES_MODIFIEES]);
        }

        if ($recap['blocages'] !== []) {
            throw ValidationException::withMessages(['annulation' => $recap['blocages']]);
        }
    }

    /** @throws ValidationException */
    private function verifierCode(string $email, string $code, string $contexte): void
    {
        $purpose = OtpPurpose::ANNULATION_EXCEPTIONNELLE;

        if ($this->otp->tooManyAttempts($email, $purpose, $contexte)) {
            throw ValidationException::withMessages(['code' => 'Trop de tentatives incorrectes : demandez un nouveau code.']);
        }

        if (! $this->otp->hasActiveCode($email, $purpose, $contexte)) {
            throw ValidationException::withMessages(['code' => 'Ce code a expiré ou a déjà été utilisé : demandez un nouveau code.']);
        }

        if (! $this->otp->verify($email, $code, $purpose, $contexte)) {
            throw ValidationException::withMessages([
                'code' => $this->otp->tooManyAttempts($email, $purpose, $contexte)
                    ? 'Trop de tentatives incorrectes : demandez un nouveau code.'
                    : 'Code incorrect.',
            ]);
        }
    }

    private function verifierOrganisation(CommandeVente $commande, User $user): void
    {
        // Explicite : le Gate::before du super admin court-circuite les Policies.
        abort_unless($commande->organization_id === $user->organization_id, 403, 'Accès refusé.');
    }

    // ── Régularisations ───────────────────────────────────────────────────────

    /**
     * Retire le gain de cashback encore en attente (jamais validé, jamais versé, sans écriture
     * comptable) et remet les compteurs du client dans l'état d'avant la vente — symétrique exact
     * de CashbackService::processVente(). Supprimé plutôt que marqué : un gain en attente n'est
     * encore qu'un calcul, la trace reste dans annulations_exceptionnelles.
     */
    private function retirerCashback(CommandeVente $commande): void
    {
        $gain = CashbackTransaction::where('vente_id', $commande->id)
            ->where('type', CashbackTransaction::TYPE_GAIN)
            ->lockForUpdate()
            ->first();

        if (! $gain) {
            return;
        }

        $solde = CashbackSolde::where('organization_id', $gain->organization_id)
            ->where('client_id', $gain->client_id)
            ->lockForUpdate()
            ->first();

        if ($solde) {
            $solde->cashback_en_attente = max(0, (int) $solde->cashback_en_attente - (int) $gain->montant);
            $solde->total_cashback_gagne = max(0, (int) $solde->total_cashback_gagne - (int) $gain->montant);
            $solde->cumul_achats = max(0, (int) $solde->cumul_achats - (int) $commande->total_commande);
            $solde->save();
        }

        $gain->delete();
    }

    // ── Lecture ───────────────────────────────────────────────────────────────

    private function gainCashback(CommandeVente $commande): ?CashbackTransaction
    {
        return CashbackTransaction::where('vente_id', $commande->id)
            ->where('type', CashbackTransaction::TYPE_GAIN)
            ->first();
    }

    /**
     * Support de trésorerie (caisse, banque, Mobile Money) réellement débité par l'écriture de
     * l'encaissement — la caisse dédiée de l'agent quand l'argent y est allé.
     */
    private function supportEncaissement(EncaissementVente $encaissement, CommandeVente $commande): ?CompteTresorerie
    {
        $piece = $this->ecritures->pieceExistantePour($commande->organization_id, $encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU);
        if (! $piece || ! $piece->isValidee()) {
            return null;
        }

        $comptes = $piece->lignes()->where('debit', '>', 0)->pluck('compte_comptable_id');
        $supports = CompteTresorerie::forOrg($commande->organization_id)->whereIn('compte_comptable_id', $comptes)->get();

        return $supports->first(fn (CompteTresorerie $s) => $s->isDediee())
            ?? $supports->first(fn (CompteTresorerie $s) => $s->site_id === $commande->facture?->site_id)
            ?? $supports->first();
    }

    // ── Clés et empreintes ────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $recap */
    private static function empreinte(array $recap): string
    {
        return hash('sha256', json_encode(self::serialiser($recap), JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<string, mixed>  $recap
     * @return array<string, mixed>
     */
    private static function serialiser(array $recap): array
    {
        unset($recap['empreinte'], $recap['blocages']);

        return json_decode(json_encode($recap, JSON_PRESERVE_ZERO_FRACTION), true);
    }

    private static function empreinteMotif(string $motif): string
    {
        return hash('sha256', trim($motif));
    }

    private static function contexte(CommandeVente $commande): string
    {
        return 'commande:'.$commande->id;
    }

    private static function cleDemande(CommandeVente $commande, User $user): string
    {
        return 'annulation-exceptionnelle:demande:'.$commande->id.':'.$user->id;
    }
}
