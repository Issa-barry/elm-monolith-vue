<?php

namespace App\Services\Ventes\Regularisation;

use App\Enums\AuditEvent;
use App\Enums\EvenementComptable;
use App\Enums\ModeConfirmationAnnulationExceptionnelle;
use App\Enums\ModeTarification;
use App\Enums\MotifRetourCommande;
use App\Enums\OtpPurpose;
use App\Enums\StatutCommandeVente;
use App\Mail\RegularisationVenteCodeMail;
use App\Models\CashbackTransaction;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommandeVenteRetour;
use App\Models\CommandeVenteRetourLigne;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\Parametre;
use App\Models\RegularisationVente;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\VenteComptabilisationService;
use App\Services\MouvementStockService;
use App\Services\OtpService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Régularisation d'une vente APRÈS encaissement (ADR 0043, docs/regularisation-vente.md) — premier
 * lot : baisse de la quantité vendue (ex. 2 700 packs saisis au lieu de 270).
 *
 * Effets, dans une seule transaction et sous verrou de la commande :
 *  - quantité facturée réduite par un mouvement tracé (CommandeVenteRetour de nature
 *    `regularisation`) : quantité livrée = chargée − retournée − régularisée, facture recalculée ;
 *  - stock : ENTRÉE de la quantité retirée (elle n'a jamais été vendue) ;
 *  - comptabilité : pièce `vente_retour` sur la valeur retirée (débit ventes, crédit client) ;
 *  - excédent encaissé, au choix de l'utilisateur : « jamais reçu » → encaissement(s) réduit(s)
 *    (contrepassation, la caisse diminue d'autant) ; « reçu » → trop-perçu à rembourser par l'action
 *    de remboursement existante (décaissement réel, opération distincte) ;
 *  - commissions recalculées immédiatement (RegularisationCommissionService), écarts sur les
 *    commissions déjà payées tracés sans toucher aux paiements.
 *
 * Permission `ventes.regulariser_apres_encaissement` (super admin par défaut). Même confirmation que
 * l'annulation exceptionnelle (code par e-mail ou confirmation simple, réglage d'organisation),
 * motif obligatoire, empreinte du récapitulatif revérifiée sous verrou.
 */
class RegularisationVenteService
{
    public const MOTIF_LONGUEUR_MIN = 10;

    private const MSG_DONNEES_MODIFIEES = 'Les données de cette commande ont changé depuis l\'affichage du récapitulatif. Rouvrez la régularisation pour vérifier les nouveaux montants.';

    private const MSG_NOUVEAU_CODE = 'Aucun code valide pour cette régularisation : demandez un nouveau code.';

    public function __construct(
        private readonly OtpService $otp,
        private readonly EcritureComptableService $ecritures,
        private readonly TresorerieDisponibiliteService $disponibilite,
        private readonly AuditLogService $audit,
        private readonly RegularisationCommissionService $commissions,
    ) {}

    /**
     * Raison pour laquelle la régularisation n'est pas proposée (état de la commande uniquement), ou
     * null. Les refus plus fins (cashback, caisse, commission non recalculable) sont dans le
     * récapitulatif.
     */
    public static function raisonNonEligible(CommandeVente $commande): ?string
    {
        if ($commande->isAnnulee() || $commande->isAnnuleeErreurSaisie() || $commande->isRetournee()) {
            return 'Cette commande est annulée ou retournée : il n\'y a plus de vente à régulariser.';
        }

        if ($commande->est_precommande) {
            return 'Une précommande se corrige par ses propres actions (retour de livraison, remboursement du trop-perçu).';
        }

        if ($commande->vehicule_id === null || $commande->requiertReceptionExplicite()) {
            return 'La régularisation après encaissement concerne les ventes livrées par un véhicule, sans validation de réception.';
        }

        if (! in_array($commande->statut, [StatutCommandeVente::LIVREE, StatutCommandeVente::CLOTUREE], true)) {
            return 'La régularisation après encaissement concerne une vente livrée et encaissée.';
        }

        $commande->loadMissing('facture');
        $facture = $commande->facture;
        if (! $facture || $facture->isAnnulee() || $facture->montantEncaisseHorsAcomptes() <= 0) {
            return 'Aucun encaissement sur cette vente : elle ne relève pas de la régularisation après encaissement.';
        }

        return null;
    }

    /**
     * Ce que la régularisation va changer, avant/après, les refus éventuels et l'empreinte de ces
     * données. Lecture seule : les quantités corrigées ne sont appliquées qu'en mémoire.
     *
     * @param  array<int, array{id?: string|null, quantite?: int|string|null}>  $lignesData  quantité CORRIGÉE (vendue) par ligne
     * @return array<string, mixed>
     *
     * @throws ValidationException quantités invalides
     */
    public function recapitulatif(CommandeVente $commande, array $lignesData, ?string $traitement): array
    {
        $commande = CommandeVente::with(['lignes.variante.produit.produitType', 'facture.encaissements.creator', 'vehicule', 'client', 'site'])
            ->findOrFail($commande->id);
        $facture = $commande->facture;

        $corrections = $this->normaliserCorrections($commande, $lignesData);
        $prixField = self::prixField($commande);

        $lignes = $commande->lignes->map(function (CommandeVenteLigne $ligne) use ($corrections, $prixField) {
            $avant = (int) $ligne->quantite_nette_chargee;
            $apres = $corrections[$ligne->id] ?? $avant;
            $prix = (float) $ligne->{$prixField};

            return [
                'id' => $ligne->id,
                'produit' => self::libelle($ligne),
                'prix_unitaire' => $prix,
                'quantite_avant' => $avant,
                'quantite_apres' => $apres,
                'quantite_retiree' => $avant - $apres,
                'montant_avant' => round($avant * $prix, 2),
                'montant_apres' => round($apres * $prix, 2),
                'reintegre_stock' => $avant > $apres && (bool) $ligne->variante?->produit?->produitType?->gere_stock,
            ];
        })->values();

        $montantAvant = round((float) $facture->montant_net, 2);
        $montantApres = round((float) $lignes->sum('montant_apres'), 2);
        $encaisse = round($facture->encaisseNet(), 2);
        $excedent = round(max(0.0, $encaisse - $montantApres), 2);
        if ($excedent <= 0) {
            $traitement = null;
        }

        // Commission recalculée sur les quantités corrigées, appliquées en mémoire seulement.
        foreach ($commande->lignes as $ligne) {
            if (isset($corrections[$ligne->id])) {
                $retire = (int) $ligne->quantite_nette_chargee - $corrections[$ligne->id];
                $ligne->quantite_retournee = (int) $ligne->quantite_retournee + $retire;
                $ligne->quantite_livree = $corrections[$ligne->id];
            }
        }
        $plan = $this->commissions->planifier($commande);

        $recap = [
            'commande' => [
                'id' => $commande->id,
                'reference' => $commande->reference,
                'statut' => $commande->statut->value,
            ],
            'facture' => [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'montant_avant' => $montantAvant,
                'montant_apres' => $montantApres,
                'encaisse' => $encaisse,
            ],
            'lignes' => $lignes->all(),
            'excedent' => $excedent,
            'traitement_excedent' => $traitement,
            'encaissements' => $traitement === RegularisationVente::EXCEDENT_NON_RECU
                ? $this->planReductionEncaissements($commande, $excedent)
                : [],
            'commissions' => [
                'total_avant' => $plan['total_avant'],
                'total_apres' => $plan['total_apres'],
                'parts' => array_map(fn (array $p) => collect($p)->except(['enveloppe_id'])->all(), $plan['parts']),
            ],
        ];

        $recap['empreinte'] = self::empreinte($recap);
        $recap['blocages'] = $this->blocages($commande, $recap, $plan['blocages']);
        $recap['mode_confirmation'] = self::mode($commande)->value;

        return $recap;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lignesData
     * @return array{destination: string, expire_minutes: int, renvoi_dans: int}
     *
     * @throws ValidationException
     */
    public function demanderCode(CommandeVente $commande, User $user, array $lignesData, ?string $traitement, string $motif, string $empreinte): array
    {
        $this->verifierOrganisation($commande, $user);

        if (self::mode($commande) === ModeConfirmationAnnulationExceptionnelle::SIMPLE) {
            throw ValidationException::withMessages([
                'code' => 'Le mode de confirmation de votre organisation ne demande pas de code : confirmez directement.',
            ]);
        }

        $this->verifierDonnees($this->recapitulatif($commande, $lignesData, $traitement), $empreinte);

        $email = (string) $user->email;
        if ($email === '') {
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

        $code = $this->otp->generate($email, OtpPurpose::REGULARISATION_VENTE, $contexte);

        try {
            Mail::to($email)->send(new RegularisationVenteCodeMail($code, (string) $commande->reference, $this->otp->ttlMinutes()));
        } catch (\Throwable $e) {
            $this->otp->clear($email, OtpPurpose::REGULARISATION_VENTE, $contexte);
            Log::error('regularisation_vente.envoi_code_echoue', [
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
     * @param  array<int, array<string, mixed>>  $lignesData
     *
     * @throws ValidationException
     */
    public function confirmer(
        CommandeVente $commande,
        User $user,
        array $lignesData,
        ?string $traitement,
        string $motif,
        string $empreinte,
        ?string $code = null,
    ): RegularisationVente {
        $this->verifierOrganisation($commande, $user);

        $mode = self::mode($commande);
        $demande = [];

        if ($mode === ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE) {
            $demande = $this->verifierConfirmationParCode($commande, $user, $lignesData, $traitement, $motif, $empreinte, $code);
        } else {
            $this->verifierDonnees($this->recapitulatif($commande, $lignesData, $traitement), $empreinte);
        }

        return DB::transaction(function () use ($commande, $user, $lignesData, $traitement, $motif, $empreinte, $demande, $mode) {
            $commande = CommandeVente::whereKey($commande->id)->lockForUpdate()->firstOrFail();
            $recap = $this->recapitulatif($commande, $lignesData, $traitement);
            $this->verifierDonnees($recap, $empreinte);

            $commande->load('lignes.variante.produit.produitType', 'facture');
            $operations = [];

            // 1. Quantités, stock, facture — mouvement tracé, jamais une correction silencieuse.
            $mouvement = $this->appliquerQuantites($commande, $recap, $motif, $user);
            $operations[] = ['type' => 'quantites_corrigees', 'mouvement_id' => $mouvement->id, 'montant' => (float) $mouvement->montant_retourne];

            if ($this->comptabiliser($mouvement, $commande)) {
                $operations[] = ['type' => 'vente_regularisee_en_comptabilite', 'montant' => (float) $mouvement->montant_retourne];
            }

            // 2. Excédent encaissé.
            if ($recap['excedent'] > 0 && $traitement === RegularisationVente::EXCEDENT_NON_RECU) {
                $operations[] = ['type' => 'encaissements_reduits', 'montant' => $recap['excedent'], 'details' => $this->reduireEncaissements($commande, $recap['encaissements'], $user)];
            } elseif ($recap['excedent'] > 0) {
                $operations[] = ['type' => 'trop_percu_a_rembourser', 'montant' => $recap['excedent']];
            }

            $regularisation = RegularisationVente::create([
                'organization_id' => $commande->organization_id,
                'commande_vente_id' => $commande->id,
                'commande_vente_retour_id' => $mouvement->id,
                'user_id' => $user->id,
                'motif' => trim($motif),
                'traitement_excedent' => $recap['excedent'] > 0 ? $traitement : null,
                'montant_avant' => $recap['facture']['montant_avant'],
                'montant_apres' => $recap['facture']['montant_apres'],
                'encaisse_avant' => $recap['facture']['encaisse'],
                'excedent' => $recap['excedent'],
                'commissions_avant' => $recap['commissions']['total_avant'],
                'commissions_apres' => $recap['commissions']['total_apres'],
                'empreinte' => $empreinte,
                'methode_confirmation' => $mode->value,
                'code_envoye_a' => $demande['destination'] ?? null,
                'code_demande_at' => isset($demande['demande_at']) ? Carbon::parse($demande['demande_at']) : null,
                'recapitulatif' => self::serialiser($recap),
                'operations' => [],
                'confirmee_at' => now(),
            ]);

            // 3. Commissions recalculées sur les quantités désormais en base.
            $plan = $this->commissions->planifier(CommandeVente::with('lignes.variante.produit.categorie')->findOrFail($commande->id));
            if ($plan['blocages'] !== []) {
                throw ValidationException::withMessages(['regularisation' => $plan['blocages']]);
            }
            $ecarts = $this->commissions->appliquer($commande, $regularisation, $plan);
            if ($plan['parts'] !== []) {
                $operations[] = [
                    'type' => 'commissions_recalculees',
                    'montant_avant' => $plan['total_avant'],
                    'montant_apres' => $plan['total_apres'],
                    'ecarts' => count($ecarts),
                ];
            }

            $regularisation->update(['operations' => $operations]);

            $this->audit->record(
                $commande,
                AuditEvent::UPDATED,
                $user,
                ['total_commande' => $recap['facture']['montant_avant']],
                ['total_commande' => $recap['facture']['montant_apres']],
                ['regularisation_vente_id' => $regularisation->id, 'motif' => trim($motif)],
            );

            CommandeVenteActiviteService::log($commande, 'regularisee', [
                'motif' => trim($motif),
                'montant_avant' => $recap['facture']['montant_avant'],
                'montant_apres' => $recap['facture']['montant_apres'],
                'excedent' => $recap['excedent'],
                'traitement_excedent' => RegularisationVente::libelleTraitementExcedent($regularisation->traitement_excedent),
            ]);

            return $regularisation;
        });
    }

    // ── Exécution ─────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $recap */
    private function appliquerQuantites(CommandeVente $commande, array $recap, string $motif, User $user): CommandeVenteRetour
    {
        $mouvement = CommandeVenteRetour::create([
            'organization_id' => $commande->organization_id,
            'commande_vente_id' => $commande->id,
            'nature' => CommandeVenteRetour::NATURE_REGULARISATION,
            'motif' => MotifRetourCommande::REGULARISATION,
            'commentaire' => trim($motif),
            'quantite_totale' => 0,
            'montant_retourne' => 0,
            'retour_total' => false,
            'created_by' => $user->id,
        ]);

        $quantiteTotale = 0;
        $montantTotal = 0.0;

        foreach ($recap['lignes'] as $l) {
            if ($l['quantite_retiree'] <= 0) {
                continue;
            }

            /** @var CommandeVenteLigne $ligne */
            $ligne = $commande->lignes->firstWhere('id', $l['id']);
            $montant = round($l['quantite_retiree'] * $l['prix_unitaire'], 2);

            $mouvementLigne = $mouvement->lignes()->create([
                'commande_vente_ligne_id' => $ligne->id,
                'variante_id' => $ligne->variante_id,
                'quantite_retournee' => $l['quantite_retiree'],
                'prix_unitaire' => $l['prix_unitaire'],
                'montant_retourne' => $montant,
                'libelle_snapshot' => $l['produit'],
            ]);

            $ligne->update([
                'quantite_retournee' => (int) $ligne->quantite_retournee + $l['quantite_retiree'],
                'quantite_livree' => $l['quantite_apres'],
                'total_ligne' => $l['montant_apres'],
            ]);

            if ($l['reintegre_stock']) {
                MouvementStockService::reintegrerRetour(
                    varianteId: $ligne->variante_id,
                    siteId: $commande->site_id,
                    orgId: $commande->organization_id,
                    quantite: $l['quantite_retiree'],
                    sourceType: CommandeVenteRetourLigne::class,
                    sourceId: $mouvementLigne->id,
                    userId: $user->id,
                );
            }

            $quantiteTotale += $l['quantite_retiree'];
            $montantTotal += $montant;
        }

        $mouvement->update(['quantite_totale' => $quantiteTotale, 'montant_retourne' => round($montantTotal, 2)]);

        CommandeVenteService::recalculerTotaux($commande);
        $commande->facture?->refresh()->recalculStatut();

        return $mouvement->load('lignes');
    }

    /**
     * Pièce `vente_retour` sur la valeur retirée. Mode shadow comme le retour de livraison : un échec
     * ne bloque pas la régularisation mais reste visible et rattrapable (comptabilite:rattraper
     * --type=retour).
     */
    private function comptabiliser(CommandeVenteRetour $mouvement, CommandeVente $commande): bool
    {
        try {
            app(VenteComptabilisationService::class)->comptabiliserRetourVente($mouvement);

            return true;
        } catch (\Throwable $e) {
            Log::warning('regularisation_vente.comptabilisation_echouee', [
                'commande_id' => $commande->id,
                'retour_id' => $mouvement->id,
                'error' => $e->getMessage(),
            ]);
            CommandeVenteActiviteService::log($commande, 'comptabilisation_retour_echouee', ['erreur' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Retire l'excédent jamais reçu, du plus récent encaissement au plus ancien. Un encaissement
     * réduit est remplacé par un encaissement du montant restant (même date, mode, compte, agence
     * et auteur) AVANT d'être supprimé : la facture ne quitte jamais « Payée » (aucun effet de bord
     * sur la commission). Les pièces de l'encaissement supprimé sont contrepassées, jamais effacées.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    private function reduireEncaissements(CommandeVente $commande, array $plan, User $user): array
    {
        $details = [];

        foreach ($plan as $ligne) {
            $original = EncaissementVente::whereKey($ligne['id'])->lockForUpdate()->firstOrFail();
            $remplacement = null;

            if ($ligne['montant_apres'] > 0) {
                $reference = $original->reference_paiement;
                // La référence Mobile Money est unique par organisation : libérée sur l'original,
                // qui est supprimé juste après, pour la reporter sur le remplaçant.
                $original->forceFill(['reference_paiement' => null, 'cle_reference_mobile_money' => null])->saveQuietly();

                $remplacement = EncaissementVente::create([
                    'facture_vente_id' => $original->facture_vente_id,
                    'site_encaissement_id' => $original->site_encaissement_id,
                    'montant' => $ligne['montant_apres'],
                    'date_encaissement' => $original->date_encaissement,
                    'mode_paiement' => $original->mode_paiement,
                    'operateur_mobile_money' => $original->operateur_mobile_money,
                    'compte_tresorerie_id' => $original->compte_tresorerie_id,
                    'reference_paiement' => $reference,
                    'note' => trim(($original->note ? $original->note.' — ' : '').'Régularisation : remplace l\'encaissement de '.number_format((float) $original->montant, 0, ',', ' ').' GNF'),
                    'est_acompte' => $original->est_acompte,
                ]);
                $remplacement->forceFill(['created_by' => $original->created_by])->saveQuietly();
            }

            $this->audit->record($commande, AuditEvent::ENCAISSEMENT_DELETED, $user, [
                'montant' => (float) $original->montant,
                'mode_paiement' => $original->mode_paiement?->value,
                'date_encaissement' => $original->date_encaissement?->toDateString(),
            ], $remplacement ? ['montant' => (float) $remplacement->montant] : null, ['regularisation_vente' => true]);

            $original->delete();

            $details[] = [
                'encaissement_id' => $ligne['id'],
                'montant_avant' => $ligne['montant_avant'],
                'montant_apres' => $ligne['montant_apres'],
                'remplace_par' => $remplacement?->id,
            ];
        }

        return $details;
    }

    // ── Récapitulatif ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $lignesData
     * @return array<string, int> quantité corrigée par ligne modifiée
     *
     * @throws ValidationException
     */
    private function normaliserCorrections(CommandeVente $commande, array $lignesData): array
    {
        $corrections = [];

        foreach ($lignesData as $data) {
            $id = (string) ($data['id'] ?? '');
            $ligne = $commande->lignes->firstWhere('id', $id);
            if (! $ligne) {
                throw ValidationException::withMessages(['lignes' => 'Une ligne de la régularisation n\'appartient pas à cette commande.']);
            }

            if (! isset($data['quantite']) || ! is_numeric($data['quantite']) || (int) $data['quantite'] < 0 || (string) (int) $data['quantite'] !== (string) $data['quantite']) {
                throw ValidationException::withMessages(['lignes' => '« '.self::libelle($ligne).' » : saisissez une quantité entière positive ou nulle.']);
            }

            $quantite = (int) $data['quantite'];
            $actuelle = (int) $ligne->quantite_nette_chargee;

            if ($quantite > $actuelle) {
                throw ValidationException::withMessages([
                    'lignes' => '« '.self::libelle($ligne)." » : la régularisation ne peut que diminuer la quantité vendue ({$actuelle} actuellement).",
                ]);
            }

            if ($quantite < $actuelle) {
                $corrections[$ligne->id] = $quantite;
            }
        }

        if ($corrections === []) {
            throw ValidationException::withMessages(['lignes' => 'Indiquez au moins une quantité inférieure à la quantité vendue.']);
        }

        $totalApres = $commande->lignes->sum(fn (CommandeVenteLigne $l) => $corrections[$l->id] ?? (int) $l->quantite_nette_chargee);
        if ($totalApres <= 0) {
            throw ValidationException::withMessages([
                'lignes' => 'Une vente ramenée à zéro n\'est pas une régularisation : utilisez l\'annulation exceptionnelle.',
            ]);
        }

        return $corrections;
    }

    /**
     * Encaissements à réduire pour retirer l'excédent, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    private function planReductionEncaissements(CommandeVente $commande, float $excedent): array
    {
        $reste = $excedent;
        $plan = [];

        $encaissements = $commande->facture->encaissements
            ->where('est_acompte', false)
            ->sortByDesc(fn (EncaissementVente $e) => [$e->date_encaissement?->toDateString(), $e->created_at?->toIso8601String()])
            ->values();

        foreach ($encaissements as $e) {
            if ($reste <= 0.004) {
                break;
            }

            $retire = round(min((float) $e->montant, $reste), 2);
            $support = $this->supportEncaissement($e, $commande);

            $plan[] = [
                'id' => $e->id,
                'date' => $e->date_encaissement?->format('d/m/Y'),
                'mode_paiement_label' => $e->mode_paiement?->label(),
                'auteur' => $e->creator?->name,
                'caisse' => $support?->libelle,
                'caisse_id' => $support?->id,
                'montant_avant' => round((float) $e->montant, 2),
                'montant_retire' => $retire,
                'montant_apres' => round((float) $e->montant - $retire, 2),
            ];
            $reste = round($reste - $retire, 2);
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $recap
     * @param  list<string>  $blocagesCommission
     * @return list<string>
     */
    private function blocages(CommandeVente $commande, array $recap, array $blocagesCommission): array
    {
        $blocages = [];

        if ($raison = self::raisonNonEligible($commande)) {
            $blocages[] = $raison;
        }

        if (CashbackTransaction::where('vente_id', $commande->id)->where('type', CashbackTransaction::TYPE_GAIN)->exists()) {
            $blocages[] = 'Cette vente a généré du cashback : la régularisation ne le recalcule pas encore. Régularisez d\'abord le cashback à la main.';
        }

        if ($recap['excedent'] > 0 && ! in_array($recap['traitement_excedent'], [RegularisationVente::EXCEDENT_NON_RECU, RegularisationVente::EXCEDENT_A_REMBOURSER], true)) {
            $blocages[] = 'Indiquez si l\'excédent encaissé a réellement été reçu.';
        }

        // Fonds retirés d'un support : ils doivent encore s'y trouver (sinon solde négatif).
        foreach (collect($recap['encaissements'])->filter(fn ($e) => $e['caisse_id'])->groupBy('caisse_id') as $caisseId => $lignes) {
            $caisse = CompteTresorerie::find($caisseId);
            $montant = (float) collect($lignes)->sum('montant_retire');
            $solde = $caisse ? $this->disponibilite->soldePourSupport($caisse) : 0.0;

            if ($solde + 0.005 < $montant) {
                $blocages[] = sprintf(
                    'L\'excédent à retirer (%s GNF) a déjà quitté « %s » (solde actuel : %s GNF). Annulez d\'abord le versement correspondant, ou choisissez « montant reçu, à rembourser ».',
                    number_format($montant, 0, ',', ' '),
                    $caisse?->libelle ?? '—',
                    number_format($solde, 0, ',', ' '),
                );
            }
        }

        foreach ($recap['encaissements'] as $e) {
            $encaissement = EncaissementVente::find($e['id']);
            if ($encaissement && ($ligne = $encaissement->ligneReglementActive())) {
                $blocages[] = $encaissement->messageReglementActif($ligne);
            }
        }

        return [...$blocages, ...$blocagesCommission];
    }

    // ── Confirmation ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $lignesData
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function verifierConfirmationParCode(CommandeVente $commande, User $user, array $lignesData, ?string $traitement, string $motif, string $empreinte, ?string $code): array
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
                'code' => 'Ce code a été demandé pour un autre motif ou d\'autres quantités : demandez un nouveau code.',
            ]);
        }

        $this->verifierDonnees($this->recapitulatif($commande, $lignesData, $traitement), $empreinte);
        $this->verifierCode($email, $code, self::contexte($commande));
        Cache::forget(self::cleDemande($commande, $user));

        return $demande;
    }

    /** @throws ValidationException */
    private function verifierCode(string $email, string $code, string $contexte): void
    {
        $purpose = OtpPurpose::REGULARISATION_VENTE;

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
            throw ValidationException::withMessages(['regularisation' => $recap['blocages']]);
        }
    }

    private function verifierOrganisation(CommandeVente $commande, User $user): void
    {
        // Explicite : le Gate::before du super admin court-circuite les Policies.
        abort_unless($commande->organization_id === $user->organization_id, 403, 'Accès refusé.');
    }

    private static function mode(CommandeVente $commande): ModeConfirmationAnnulationExceptionnelle
    {
        return Parametre::getModeConfirmationAnnulationExceptionnelle($commande->organization_id);
    }

    // ── Outils ────────────────────────────────────────────────────────────────

    /** Support de trésorerie réellement débité par l'écriture de l'encaissement. */
    private function supportEncaissement(EncaissementVente $encaissement, CommandeVente $commande): ?CompteTresorerie
    {
        $piece = $this->ecritures->pieceExistantePour($commande->organization_id, $encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU);
        if (! $piece || ! $piece->isValidee()) {
            return null;
        }

        $comptes = $piece->lignes()->where('debit', '>', 0)->pluck('compte_comptable_id');
        /** @var Collection<int, CompteTresorerie> $supports */
        $supports = CompteTresorerie::forOrg($commande->organization_id)->whereIn('compte_comptable_id', $comptes)->get();
        $siteEncaissement = $encaissement->site_encaissement_id ?? $commande->facture?->site_id;

        return $supports->first(fn (CompteTresorerie $s) => $s->isDediee())
            ?? $supports->first(fn (CompteTresorerie $s) => $s->site_id === $siteEncaissement)
            ?? $supports->first();
    }

    private static function prixField(CommandeVente $commande): string
    {
        return $commande->mode_tarification_snapshot === ModeTarification::PRIX_USINE
            ? 'prix_usine_snapshot'
            : 'prix_vente_snapshot';
    }

    private static function libelle(CommandeVenteLigne $ligne): string
    {
        return $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? 'Produit';
    }

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
        unset($recap['empreinte'], $recap['blocages'], $recap['mode_confirmation']);

        return json_decode(json_encode($recap, JSON_PRESERVE_ZERO_FRACTION), true);
    }

    private static function empreinteMotif(string $motif): string
    {
        return hash('sha256', trim($motif));
    }

    private static function contexte(CommandeVente $commande): string
    {
        return 'regularisation:'.$commande->id;
    }

    private static function cleDemande(CommandeVente $commande, User $user): string
    {
        return 'regularisation-vente:demande:'.$commande->id.':'.$user->id;
    }
}
