<?php

namespace App\Services\Achats;

use App\Enums\StatutCommandeAchat;
use App\Enums\StatutFactureFournisseur;
use App\Enums\TypeJustificatifAchat;
use App\Models\CommandeAchat;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurLigne;
use App\Models\ReceptionAchatLigne;
use App\Models\User;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
use App\Services\ReferenceNumeroService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Factures fournisseurs (ADR 0022) :
 * - une facture appartient à UN bon de commande validé, et à son fournisseur ;
 * - elle facture des lignes de réception (une ou plusieurs réceptions) : chaque quantité reçue
 *   n'est facturable qu'une fois, contrôlé sous verrou à la validation ;
 * - la dette naît à la validation (TTC, payé = 0, reste dû = TTC) ; montants et libellés figés ;
 * - validation : permission + agence du bon dans le périmètre « Peut acheter pour » + créateur et
 *   dernier modificateur ≠ validateur, sans passe-droit de rôle ;
 * - la comptabilisation suit la validation, sans la bloquer (comptes d'achat/TVA à paramétrer).
 */
class FactureFournisseurService
{
    public const PREFIXE_REFERENCE = 'FAF';

    public function __construct(
        private readonly ReferenceNumeroService $references,
        private readonly PerimetreCommandesAchat $perimetre,
        private readonly FactureFournisseurComptabilisationService $comptabilisation,
    ) {}

    public static function reglesSaisie(): array
    {
        return [
            'fournisseur_id' => ['required', 'string'],
            // Facultatif : certains fournisseurs ne remettent aucun document, ou un document sans numéro.
            'numero_facture_fournisseur' => ['nullable', 'string', 'max:100'],
            'type_justificatif' => ['nullable', Rule::enum(TypeJustificatifAchat::class)],
            'date_facture' => ['required', 'date', 'before_or_equal:today'],
            'date_echeance' => ['nullable', 'date', 'after_or_equal:date_facture'],
            'taux_tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.reception_ligne_id' => ['required', 'string', 'distinct'],
            'lignes.*.qte' => ['required', 'integer', 'min:1'],
            'lignes.*.prix_unitaire' => ['required', 'numeric', 'min:0'],
        ];
    }

    public static function messagesSaisie(): array
    {
        return [
            'type_justificatif.enum' => 'Type de justificatif inconnu.',
            'date_facture.before_or_equal' => 'La date de facture ne peut pas être dans le futur.',
            'date_echeance.after_or_equal' => "L'échéance ne peut pas précéder la date de facture.",
            'lignes.required' => 'Sélectionnez au moins une ligne reçue à facturer.',
            'lignes.min' => 'Sélectionnez au moins une ligne reçue à facturer.',
            'lignes.*.reception_ligne_id.distinct' => 'Cette ligne reçue figure déjà sur la facture.',
            'lignes.*.qte.min' => 'La quantité facturée doit être supérieure à 0.',
        ];
    }

    /**
     * Lignes de réception du bon avec leurs quantités facturables : reçu − déjà facturé sur des
     * factures validées (hors $exclureFactureId). Seule source de cette règle (formulaire et
     * contrôle serveur).
     *
     * @return Collection<string, array{ligne: ReceptionAchatLigne, deja_facture: int, facturable: int}>
     */
    public function lignesFacturables(CommandeAchat $commande, ?string $exclureFactureId = null, bool $verrouiller = false): Collection
    {
        $query = ReceptionAchatLigne::query()
            ->whereHas('reception', fn ($q) => $q->where('commande_achat_id', $commande->id))
            ->with(['reception', 'commandeLigne']);
        if ($verrouiller) {
            $query->lockForUpdate();
        }
        $lignes = $query->get();

        // Jointure (jamais une sous-requête whereHas) + lecture verrouillante : sous InnoDB
        // REPEATABLE READ, une lecture simple — ou une sous-requête, même sous un FOR UPDATE — lit la
        // vue cohérente de la transaction, figée à sa première lecture, et ignorerait une facture
        // validée et commitée entre-temps par une autre validation (preuve :
        // FactureFournisseurConcurrenceTest). Le FOR UPDATE lit la dernière version validée.
        $dejaQuery = FactureFournisseurLigne::query()
            ->join('factures_fournisseurs', 'factures_fournisseurs.id', '=', 'facture_fournisseur_lignes.facture_fournisseur_id')
            ->whereIn('facture_fournisseur_lignes.reception_achat_ligne_id', $lignes->pluck('id'))
            ->whereIn('factures_fournisseurs.statut', array_map(fn ($s) => $s->value, StatutFactureFournisseur::constatees()))
            ->when($exclureFactureId, fn ($q) => $q->where('factures_fournisseurs.id', '!=', $exclureFactureId))
            ->select(['facture_fournisseur_lignes.reception_achat_ligne_id', 'facture_fournisseur_lignes.qte_facturee']);
        if ($verrouiller) {
            $dejaQuery->lockForUpdate();
        }
        $deja = $dejaQuery->get()
            ->groupBy('reception_achat_ligne_id')
            ->map(fn ($lignesFacturees) => (int) $lignesFacturees->sum('qte_facturee'));

        return $lignes->mapWithKeys(function (ReceptionAchatLigne $l) use ($deja) {
            $dejaFacture = (int) ($deja[$l->id] ?? 0);

            return [$l->id => [
                'ligne' => $l,
                'deja_facture' => $dejaFacture,
                'facturable' => max(0, (int) $l->qte_recue - $dejaFacture),
            ]];
        });
    }

    public function creer(CommandeAchat $commande, User $user, array $data): FactureFournisseur
    {
        $this->verifierCommande($commande, $user);
        $this->verifierFournisseur($commande, $data);

        return $this->sansDoublonDeNumero(fn () => DB::transaction(function () use ($commande, $user, $data) {
            $lignes = $this->lignesSaisies($commande, $data, null);
            $this->verifierNumeroUnique($commande, self::numero($data), null);

            [$reference, $numero] = $this->references->generer($commande->organization_id, self::PREFIXE_REFERENCE);

            $facture = FactureFournisseur::create([
                'organization_id' => $commande->organization_id,
                'commande_achat_id' => $commande->id,
                'fournisseur_id' => $commande->fournisseur_id,
                // Agence PAYEUSE du bon : la facture, la dette et le paiement lui appartiennent.
                'site_id' => $commande->sitePayeurId(),
                'reference' => $reference,
                'numero' => $numero,
                'statut' => StatutFactureFournisseur::BROUILLON,
                'contenu_modifie_par' => $user->id,
                'contenu_modifie_at' => now(),
                'created_by' => $user->id,
            ] + $this->entete($data));

            $this->ecrireLignes($facture, $lignes, $data);

            return $facture;
        }));
    }

    public function modifier(FactureFournisseur $facture, User $user, array $data): FactureFournisseur
    {
        $commande = $facture->commande;
        $this->verifierCommande($commande, $user);
        $this->verifierFournisseur($commande, $data);

        return $this->sansDoublonDeNumero(fn () => DB::transaction(function () use ($facture, $commande, $user, $data) {
            $facture = FactureFournisseur::whereKey($facture->id)->lockForUpdate()->firstOrFail();
            if (! $facture->isBrouillon()) {
                throw ValidationException::withMessages(['facture' => 'Seule une facture en brouillon peut être modifiée.']);
            }

            $lignes = $this->lignesSaisies($commande, $data, $facture->id);
            $this->verifierNumeroUnique($commande, self::numero($data), $facture->id);

            $facture->update(['contenu_modifie_par' => $user->id, 'contenu_modifie_at' => now()] + $this->entete($data));
            $facture->lignes()->delete();
            $this->ecrireLignes($facture, $lignes, $data);

            return $facture;
        }));
    }

    /** Pourquoi cet utilisateur ne peut pas valider cette facture, ou null s'il le peut. */
    public function motifNonValidable(FactureFournisseur $facture, User $user): ?string
    {
        if (! $facture->isBrouillon()) {
            return "Cette facture n'est pas un brouillon.";
        }
        // checkPermissionTo() lit les permissions réelles des rôles — jamais le Gate::before.
        if (! $user->checkPermissionTo('factures-fournisseurs.valider')) {
            return "Vous n'avez pas la permission de valider les factures d’achat.";
        }
        if (! $this->perimetre->couvreSite($user, $facture->site_id)) {
            return "L'agence de cette facture n'est pas dans votre périmètre d'achat.";
        }

        // Séparation saisie/validation, sauf si une règle du rôle couvrant l'agence autorise à valider
        // ses propres factures (Paramètres → Achats ; activé par défaut pour le super administrateur).
        $estAuteur = $facture->created_by !== null && $facture->created_by === $user->id;
        $estModificateur = $facture->contenu_modifie_par !== null && $facture->contenu_modifie_par === $user->id;
        if (($estAuteur || $estModificateur) && ! $this->perimetre->peutValiderSesPropresFactures($user, $facture->site_id)) {
            return $estAuteur
                ? 'Vous avez saisi cette facture : votre rôle ne permet pas de valider vos propres factures, elle doit être validée par une autre personne.'
                : 'Vous avez modifié cette facture en dernier : votre rôle ne permet pas de valider vos propres factures, elle doit être validée par une autre personne.';
        }

        return null;
    }

    /**
     * Valide la facture : quantités recontrôlées sous verrou des lignes de réception (aucune
     * quantité reçue facturée deux fois, même par deux validations concurrentes), dette constatée,
     * montants et libellés figés. La pièce comptable est passée après le commit, sans bloquer.
     */
    public function valider(FactureFournisseur $facture, User $user): FactureFournisseur
    {
        $facture = DB::transaction(function () use ($facture, $user) {
            $facture = FactureFournisseur::whereKey($facture->id)->lockForUpdate()->firstOrFail();
            $facture->load(['lignes', 'commande', 'fournisseur.personne', 'fournisseur.entrepriseTierce']);

            $refus = $this->motifNonValidable($facture, $user);
            if ($refus !== null) {
                throw ValidationException::withMessages(['validation' => $refus]);
            }
            if ($facture->lignes->isEmpty()) {
                throw ValidationException::withMessages(['validation' => 'La facture ne contient aucune ligne.']);
            }

            $facturables = $this->lignesFacturables($facture->commande, $facture->id, verrouiller: true);
            foreach ($facture->lignes as $ligne) {
                $restant = $facturables->get($ligne->reception_achat_ligne_id)['facturable'] ?? 0;
                if ($ligne->qte_facturee > $restant) {
                    throw ValidationException::withMessages([
                        'validation' => "« {$ligne->libelle_snapshot} » : {$ligne->qte_facturee} facturés, mais seulement {$restant} reçus et non encore facturés.",
                    ]);
                }
            }

            $facture->update([
                'statut' => StatutFactureFournisseur::VALIDEE,
                'validee_at' => now(),
                'validee_par' => $user->id,
                'montant_paye' => 0,
                'fournisseur_nom_snapshot' => $facture->fournisseur?->nom_complet,
            ]);

            return $facture;
        });

        $this->comptabiliser($facture);

        return $facture;
    }

    /**
     * Annule un brouillon, ou une facture validée tant qu'aucun paiement n'a eu lieu. ATOMIQUE :
     * la contrepassation de la pièce comptable (si elle existe) se fait dans la MÊME transaction
     * que le changement de statut — si elle échoue, rien n'est annulé (jamais une facture annulée
     * dont l'écriture resterait active). Une facture validée encore en attente de comptabilisation
     * s'annule sans pièce à contrepasser. Les quantités et le numéro redeviennent disponibles.
     */
    public function annuler(FactureFournisseur $facture, User $user, string $motif): FactureFournisseur
    {
        try {
            return DB::transaction(function () use ($facture, $user, $motif) {
                $facture = FactureFournisseur::whereKey($facture->id)->lockForUpdate()->firstOrFail();

                if ($facture->statut === StatutFactureFournisseur::ANNULEE) {
                    throw ValidationException::withMessages(['motif_annulation' => 'Cette facture est déjà annulée.']);
                }
                if ((float) $facture->montant_paye > 0) {
                    throw ValidationException::withMessages(['motif_annulation' => 'Une facture déjà payée, même en partie, ne peut pas être annulée.']);
                }
                if (! $this->perimetre->couvreSite($user, $facture->site_id)) {
                    throw ValidationException::withMessages(['motif_annulation' => "L'agence de cette facture n'est pas dans votre périmètre d'achat."]);
                }

                $etaitValidee = $facture->statut === StatutFactureFournisseur::VALIDEE;
                $facture->update([
                    'statut' => StatutFactureFournisseur::ANNULEE,
                    'cle_numero_unique' => null,
                    'annulee_at' => now(),
                    'annulee_par' => $user->id,
                    'motif_annulation' => $motif,
                ]);

                if ($etaitValidee) {
                    $this->comptabilisation->annuler($facture, $motif, $user->id);
                }

                return $facture;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Annulation facture fournisseur refusée : contrepassation impossible', ['facture_id' => $facture->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'motif_annulation' => "La facture n'a pas été annulée : son écriture comptable n'a pas pu être contrepassée ({$e->getMessage()}).",
            ]);
        }
    }

    /**
     * Passe (ou repasse) la pièce comptable d'une facture validée. Non bloquant : un échec (compte
     * d'achat ou de TVA non mappé…) est enregistré sur la facture et journalisé ; la pièce sera
     * passée au prochain essai (relance depuis la fiche ou `comptabilite:rattraper`).
     */
    public function comptabiliser(FactureFournisseur $facture): bool
    {
        try {
            $this->comptabilisation->comptabiliserFactureValidee($facture);
            $facture->forceFill(['comptabilisation_erreur' => null])->saveQuietly();

            return true;
        } catch (\Throwable $e) {
            $facture->forceFill(['comptabilisation_erreur' => $e->getMessage()])->saveQuietly();
            Log::warning('Comptabilisation facture fournisseur en attente', ['facture_id' => $facture->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Filet de sécurité de verifierNumeroUnique() : deux saisies simultanées du même numéro passent
     * toutes deux le contrôle applicatif, l'index unique en base refuse la seconde.
     */
    private function sansDoublonDeNumero(callable $operation): FactureFournisseur
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $e) {
            // MySQL cite l'index, SQLite les colonnes.
            if (! str_contains($e->getMessage(), 'factures_fournisseurs_numero_unique') && ! str_contains($e->getMessage(), 'cle_numero_unique')) {
                throw $e;
            }
            throw ValidationException::withMessages(['numero_facture_fournisseur' => 'Une facture de ce fournisseur porte déjà ce numéro.']);
        }
    }

    private function verifierCommande(CommandeAchat $commande, User $user): void
    {
        $facturable = $commande->validee_at !== null
            && in_array($commande->statut, [
                StatutCommandeAchat::VALIDEE, StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE,
                StatutCommandeAchat::RECEPTIONNEE, StatutCommandeAchat::CLOTUREE,
            ], true);
        if (! $facturable) {
            throw ValidationException::withMessages(['commande' => 'Seul un bon de commande validé peut être facturé.']);
        }
        if (! $this->perimetre->couvreSite($user, $commande->sitePayeurId())) {
            throw ValidationException::withMessages(['commande' => "L'agence qui paie ce bon de commande n'est pas dans votre périmètre d'achat."]);
        }
    }

    private function verifierFournisseur(CommandeAchat $commande, array $data): void
    {
        if (($data['fournisseur_id'] ?? null) !== $commande->fournisseur_id) {
            throw ValidationException::withMessages(['fournisseur_id' => 'Le fournisseur de la facture doit être celui du bon de commande.']);
        }
    }

    /** Numéro du fournisseur saisi, ou null s'il n'y en a pas. */
    private static function numero(array $data): ?string
    {
        $numero = trim((string) ($data['numero_facture_fournisseur'] ?? ''));

        return $numero === '' ? null : $numero;
    }

    private function verifierNumeroUnique(CommandeAchat $commande, ?string $numero, ?string $exclure): void
    {
        if ($numero === null) {
            return;
        }

        $doublon = FactureFournisseur::where('organization_id', $commande->organization_id)
            ->where('fournisseur_id', $commande->fournisseur_id)
            ->where('numero_facture_fournisseur', trim($numero))
            ->where('statut', '!=', StatutFactureFournisseur::ANNULEE->value)
            ->when($exclure, fn ($q) => $q->where('id', '!=', $exclure))
            ->exists();
        if ($doublon) {
            throw ValidationException::withMessages(['numero_facture_fournisseur' => 'Une facture de ce fournisseur porte déjà ce numéro.']);
        }
    }

    /** @return Collection<int, array{ligne: ReceptionAchatLigne, deja_facture: int, facturable: int}> */
    private function lignesSaisies(CommandeAchat $commande, array $data, ?string $factureId): Collection
    {
        $facturables = $this->lignesFacturables($commande, $factureId);
        $erreurs = [];
        $retenues = collect();

        foreach ($data['lignes'] as $i => $saisie) {
            $info = $facturables->get($saisie['reception_ligne_id']);
            if ($info === null) {
                $erreurs["lignes.{$i}.reception_ligne_id"] = "Cette ligne n'a pas été reçue sur ce bon de commande.";

                continue;
            }
            if ((int) $saisie['qte'] > $info['facturable']) {
                $erreurs["lignes.{$i}.qte"] = "{$saisie['qte']} facturés, mais seulement {$info['facturable']} reçus et non encore facturés.";

                continue;
            }
            $retenues->put($i, $info);
        }

        if ($erreurs !== []) {
            throw ValidationException::withMessages($erreurs);
        }

        return $retenues;
    }

    private function entete(array $data): array
    {
        $numero = self::numero($data);
        $type = TypeJustificatifAchat::tryFrom((string) ($data['type_justificatif'] ?? '')) ?? TypeJustificatifAchat::FACTURE;
        if ($type === TypeJustificatifAchat::AUCUN && $numero !== null) {
            throw ValidationException::withMessages([
                'numero_facture_fournisseur' => 'Sans document du fournisseur, le numéro doit rester vide.',
            ]);
        }

        return [
            'numero_facture_fournisseur' => $numero,
            'type_justificatif' => $type,
            // Clé du contrôle de doublon : absente sans numéro (plusieurs achats sans numéro
            // coexistent), présente dès qu'un numéro est saisi.
            'cle_numero_unique' => $numero,
            'date_facture' => $data['date_facture'],
            'date_echeance' => $data['date_echeance'] ?? null,
            'taux_tva' => (float) ($data['taux_tva'] ?? 0),
            'note' => $data['note'] ?? null,
        ];
    }

    private function ecrireLignes(FactureFournisseur $facture, Collection $lignes, array $data): void
    {
        $ht = 0.0;

        foreach ($lignes as $i => $info) {
            $saisie = $data['lignes'][$i];
            $qte = (int) $saisie['qte'];
            $prix = round((float) $saisie['prix_unitaire'], 2);
            $total = round($qte * $prix, 2);
            $commandeLigne = $info['ligne']->commandeLigne;

            $facture->lignes()->create([
                'reception_achat_ligne_id' => $info['ligne']->id,
                'commande_achat_ligne_id' => $info['ligne']->commande_achat_ligne_id,
                'variante_id' => $info['ligne']->variante_id,
                'libelle_snapshot' => $commandeLigne?->libelle_snapshot,
                'reference_snapshot' => $commandeLigne?->reference_snapshot,
                'qte_facturee' => $qte,
                'prix_unitaire' => $prix,
                'total_ht' => $total,
            ]);
            $ht += $total;
        }

        $tva = round($ht * (float) $facture->taux_tva / 100, 2);
        $facture->update([
            'montant_ht' => round($ht, 2),
            'montant_tva' => $tva,
            'montant_ttc' => round($ht + $tva, 2),
        ]);
    }
}
