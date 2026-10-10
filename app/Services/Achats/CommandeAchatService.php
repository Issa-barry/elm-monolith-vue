<?php

namespace App\Services\Achats;

use App\Enums\StatutCommandeAchat;
use App\Jobs\NotifierCommandeAchatJob;
use App\Models\CommandeAchat;
use App\Models\ProduitVariante;
use App\Models\RegleValidationRole;
use App\Models\User;
use App\Services\ReferenceNumeroService;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Cycle d'un bon de commande fournisseur (ADR 0021) : création directe « à valider », modification
 * tant qu'il n'est pas validé, validation (permission + plafond du rôle + séparation des tâches,
 * levée par le réglage « peut valider ses propres bons » de la règle du rôle),
 * annulation (motif, quel que soit le plafond) et clôture du reliquat.
 *
 * Toutes les règles sont vérifiées ICI, sous verrou — jamais seulement dans la policy, que le
 * Gate::before du super administrateur court-circuite. Le super administrateur est soumis aux
 * mêmes règles que tout le monde : sa règle par défaut l'autorise à valider ses propres bons.
 *
 * Les notifications partent dans un job mis en file APRÈS le commit : jamais pour une opération
 * annulée par un rollback.
 */
class CommandeAchatService
{
    public const PREFIXE_REFERENCE = 'BC';

    public function __construct(
        private readonly AchatReferentielValidator $referentiel,
        private readonly ReferenceNumeroService $references,
        private readonly ValidationParPlafondService $plafonds,
    ) {}

    /** Règles de saisie communes à la création et à la modification. */
    public static function reglesSaisie(): array
    {
        return [
            'site_id' => ['required', 'string'],
            'site_payeur_id' => ['nullable', 'string'],
            'fournisseur_id' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.variante_id' => ['required', 'string', 'distinct'],
            'lignes.*.qte' => ['required', 'integer', 'min:1'],
            'lignes.*.prix_achat' => ['required', 'numeric', 'min:0'],
        ];
    }

    public static function messagesSaisie(): array
    {
        return [
            'site_id.required' => "L'agence est obligatoire.",
            'fournisseur_id.required' => 'Le fournisseur est obligatoire.',
            'lignes.required' => 'Au moins une ligne est requise.',
            'lignes.min' => 'Au moins une ligne est requise.',
            'lignes.*.variante_id.required' => 'Le produit est obligatoire.',
            'lignes.*.variante_id.distinct' => 'Ce produit figure déjà sur une autre ligne.',
            'lignes.*.qte.required' => 'La quantité est obligatoire.',
            'lignes.*.qte.min' => 'La quantité doit être supérieure à 0.',
            'lignes.*.prix_achat.required' => "Le prix d'achat est obligatoire.",
            'lignes.*.prix_achat.min' => "Le prix d'achat ne peut pas être négatif.",
        ];
    }

    public function creer(User $user, array $data): CommandeAchat
    {
        $ref = $this->referentiel->verifier($user, $data);

        return DB::transaction(function () use ($user, $data, $ref) {
            [$reference, $numero] = $this->references->generer($user->organization_id, self::PREFIXE_REFERENCE);

            $commande = CommandeAchat::create([
                'organization_id' => $user->organization_id,
                'site_id' => $ref['site']->id,
                'site_payeur_id' => $ref['site_payeur']->id,
                'fournisseur_id' => $ref['fournisseur']->id,
                'reference' => $reference,
                'numero' => $numero,
                'note' => $data['note'] ?? null,
                'statut' => StatutCommandeAchat::A_VALIDER,
                'total_commande' => 0,
                'contenu_modifie_par' => $user->id,
                'contenu_modifie_at' => now(),
            ]);

            $this->ecrireLignes($commande, $data['lignes'], $ref['variantes']);

            NotifierCommandeAchatJob::dispatch($commande->id, NotifierCommandeAchatJob::CREEE, $user->id)->afterCommit();

            return $commande;
        });
    }

    public function modifier(CommandeAchat $commande, User $user, array $data): CommandeAchat
    {
        $ref = $this->referentiel->verifier($user, $data);

        return DB::transaction(function () use ($commande, $user, $data, $ref) {
            $commande = $this->verrouiller($commande);

            if (! $commande->isAValider()) {
                throw ValidationException::withMessages([
                    'commande' => 'Une commande validée, réceptionnée ou annulée ne peut plus être modifiée.',
                ]);
            }

            $commande->update([
                'site_id' => $ref['site']->id,
                'site_payeur_id' => $ref['site_payeur']->id,
                'fournisseur_id' => $ref['fournisseur']->id,
                'note' => $data['note'] ?? null,
                'contenu_modifie_par' => $user->id,
                'contenu_modifie_at' => now(),
            ]);

            $commande->lignes()->delete();
            $this->ecrireLignes($commande, $data['lignes'], $ref['variantes']);

            return $commande;
        });
    }

    /**
     * Pourquoi cet utilisateur ne peut pas valider cette commande (message affiché tel quel), ou
     * null s'il le peut. Seule source de ces règles : la fiche s'en sert pour afficher ou non le
     * bouton, valider() pour refuser.
     */
    public function motifNonValidable(CommandeAchat $commande, User $user): ?string
    {
        if (! $commande->isAValider()) {
            return "Cette commande n'est pas en attente de validation.";
        }
        // checkPermissionTo() lit les permissions réelles des rôles — jamais le Gate::before.
        if (! $user->checkPermissionTo('achats.valider')) {
            return "Votre rôle n'a pas la permission de valider les bons de commande.";
        }
        if ($commande->site_id === null || $commande->fournisseur_id === null) {
            return "Renseignez l'agence et le fournisseur de la commande avant de la valider.";
        }

        // Deux agences (décision du 10/10/2026) : le plafond est celui d'une règle couvrant l'agence
        // PAYEUSE, dont la trésorerie est engagée ; l'agence de livraison doit aussi être couverte.
        $payeur = $commande->sitePayeurId();
        $montant = $this->montant($commande);
        $refus = $this->plafonds->motifRefus($user, RegleValidationRole::DOMAINE_ACHATS, $payeur, $montant);
        if ($refus !== null) {
            return $refus;
        }
        if ($payeur !== $commande->site_id && ! $this->plafonds->couvreSite($user, RegleValidationRole::DOMAINE_ACHATS, $commande->site_id)) {
            return "L'agence de livraison de ce bon n'est pas dans votre périmètre d'achat.";
        }

        // Séparation des tâches, sauf si une règle du rôle autorise à valider ses propres bons
        // (Paramètres → Achats) pour cette agence et ce montant.
        $estAuteur = $this->estAuteur($commande, $user);
        if ($estAuteur !== null
            && $this->plafonds->motifRefus($user, RegleValidationRole::DOMAINE_ACHATS, $payeur, $montant, sonPropreBon: true) !== null) {
            return $estAuteur === 'createur'
                ? 'Vous avez créé ce bon de commande : votre rôle ne permet pas de valider vos propres bons, il doit être validé par une autre personne.'
                : 'Vous avez modifié ce bon de commande en dernier : votre rôle ne permet pas de valider vos propres bons, il doit être validé par une autre personne.';
        }

        return null;
    }

    /** 'createur', 'modificateur' ou null si l'utilisateur n'est pas l'auteur du bon. */
    private function estAuteur(CommandeAchat $commande, User $user): ?string
    {
        if ($commande->created_by !== null && $commande->created_by === $user->id) {
            return 'createur';
        }
        if ($commande->contenu_modifie_par !== null && $commande->contenu_modifie_par === $user->id) {
            return 'modificateur';
        }

        return null;
    }

    /**
     * Utilisateurs actifs qui pourraient valider ce bon maintenant, selon motifNonValidable() :
     * même source pour les notifications de création et pour le message de la fiche quand
     * personne d'autre ne peut valider.
     *
     * @return Collection<int, User>
     */
    public function validateursPossibles(CommandeAchat $commande): Collection
    {
        if (! Permission::where('name', 'achats.valider')->where('guard_name', 'web')->exists()) {
            return collect();
        }

        return User::permission('achats.valider')
            ->where('organization_id', $commande->organization_id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->get()
            ->filter(fn (User $u) => $this->motifNonValidable($commande, $u) === null)
            ->values();
    }

    /**
     * Valide la commande. Le montant est relu sous verrou puis figé dans `montant_valide`, avec un
     * snapshot du fournisseur, de l'agence, des libellés/références des lignes et de la règle de
     * plafond qui a autorisé la validation.
     */
    public function valider(CommandeAchat $commande, User $user): CommandeAchat
    {
        return DB::transaction(function () use ($commande, $user) {
            $commande = $this->verrouiller($commande);
            $commande->load(['lignes', 'fournisseur.personne', 'fournisseur.entrepriseTierce', 'site', 'sitePayeur']);

            if ($commande->lignes->isEmpty()) {
                throw ValidationException::withMessages(['validation' => 'La commande ne contient aucune ligne.']);
            }

            $refus = $this->motifNonValidable($commande, $user);
            if ($refus !== null) {
                throw ValidationException::withMessages(['validation' => $refus]);
            }

            $montant = $this->montant($commande);
            $sonPropreBon = $this->estAuteur($commande, $user) !== null;
            $regle = $this->plafonds->regleAppliquee($user, RegleValidationRole::DOMAINE_ACHATS, $commande->sitePayeurId(), $montant, $sonPropreBon);

            $this->figerLignes($commande);

            $commande->update([
                'statut' => StatutCommandeAchat::VALIDEE,
                'total_commande' => $montant,
                'montant_valide' => $montant,
                'validee_at' => now(),
                'validee_par' => $user->id,
                'fournisseur_nom_snapshot' => $commande->fournisseur?->nom_complet,
                'site_nom_snapshot' => $commande->site?->nom,
                'site_payeur_nom_snapshot' => $commande->estPayeParUneAutreAgence() ? $commande->sitePayeur?->nom : $commande->site?->nom,
                'validation_regle_snapshot' => [
                    'role' => $regle->role_name,
                    'role_label' => Role::where('name', $regle->role_name)->value('label') ?: $regle->role_name,
                    'plafond' => $regle->plafond_illimite ? null : (float) $regle->plafond,
                    'plafond_illimite' => (bool) $regle->plafond_illimite,
                    'perimetre' => $regle->perimetre,
                    'sites' => $regle->sites,
                    'son_propre_bon' => $sonPropreBon,
                ],
            ]);

            NotifierCommandeAchatJob::dispatch($commande->id, NotifierCommandeAchatJob::VALIDEE, $user->id)->afterCommit();

            return $commande;
        });
    }

    /** Annulation avec motif, possible même au-dessus du plafond, tant que rien n'a été reçu. */
    public function annuler(CommandeAchat $commande, User $user, string $motif): CommandeAchat
    {
        return DB::transaction(function () use ($commande, $user, $motif) {
            $commande = $this->verrouiller($commande);

            if ($commande->isAnnulee()) {
                throw ValidationException::withMessages(['motif_annulation' => 'Cette commande est déjà annulée.']);
            }

            $dejaRecu = (int) $commande->lignes()->sum('qte_recue') > 0;
            $annulable = $commande->isAValider() || $commande->statut === StatutCommandeAchat::VALIDEE;
            if (! $annulable || $dejaRecu) {
                throw ValidationException::withMessages([
                    'motif_annulation' => 'Une commande déjà réceptionnée ne peut pas être annulée : clôturez son reliquat.',
                ]);
            }

            $commande->update([
                'statut' => StatutCommandeAchat::ANNULEE,
                'motif_annulation' => $motif,
                'annulee_at' => now(),
                'annulee_par' => $user->id,
            ]);

            NotifierCommandeAchatJob::dispatch($commande->id, NotifierCommandeAchatJob::ANNULEE, $user->id)->afterCommit();

            return $commande;
        });
    }

    /** Abandonne le reliquat d'une commande partiellement reçue : plus aucune réception possible. */
    public function cloturerReliquat(CommandeAchat $commande, User $user, string $motif): CommandeAchat
    {
        return DB::transaction(function () use ($commande, $user, $motif) {
            $commande = $this->verrouiller($commande);

            if ($commande->statut !== StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE) {
                throw ValidationException::withMessages([
                    'motif_cloture' => 'Seule une commande partiellement réceptionnée peut être clôturée.',
                ]);
            }

            $commande->update([
                'statut' => StatutCommandeAchat::CLOTUREE,
                'motif_cloture' => $motif,
                'cloturee_at' => now(),
                'cloturee_par' => $user->id,
            ]);

            return $commande;
        });
    }

    private function montant(CommandeAchat $commande): float
    {
        return (float) $commande->lignes()->sum('total_ligne');
    }

    private function verrouiller(CommandeAchat $commande): CommandeAchat
    {
        return CommandeAchat::whereKey($commande->id)->lockForUpdate()->firstOrFail();
    }

    /** Libellé et référence de chaque ligne relus sur la variante au moment de la validation. */
    private function figerLignes(CommandeAchat $commande): void
    {
        $variantes = ProduitVariante::whereIn('id', $commande->lignes->pluck('variante_id')->filter())
            ->with('produit')
            ->get()
            ->keyBy('id');

        foreach ($commande->lignes as $ligne) {
            $variante = $variantes->get($ligne->variante_id);
            if ($variante === null) {
                continue;
            }
            $ligne->update([
                'libelle_snapshot' => $this->referentiel->libelle($variante),
                'reference_snapshot' => $variante->sku,
            ]);
        }
    }

    private function ecrireLignes(CommandeAchat $commande, array $lignes, $variantes): void
    {
        $total = 0.0;

        foreach ($lignes as $ligne) {
            $variante = $variantes->get($ligne['variante_id']);
            $qte = (int) $ligne['qte'];
            $prix = (float) $ligne['prix_achat'];
            $totalLigne = $qte * $prix;

            $commande->lignes()->create([
                'variante_id' => $variante->id,
                'qte' => $qte,
                'prix_achat_snapshot' => $prix,
                'total_ligne' => $totalLigne,
                'libelle_snapshot' => $this->referentiel->libelle($variante),
                'reference_snapshot' => $variante->sku,
            ]);

            $total += $totalLigne;
        }

        $commande->update(['total_commande' => $total]);
    }
}
