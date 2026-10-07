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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Cycle d'un bon de commande fournisseur (ADR 0021) : création directe « à valider », modification
 * tant qu'il n'est pas validé, validation (permission + plafond du rôle + séparation des tâches),
 * annulation (motif, quel que soit le plafond) et clôture du reliquat.
 *
 * Toutes les règles sont vérifiées ICI, sous verrou — jamais seulement dans la policy, que le
 * Gate::before du super administrateur court-circuite. Le super administrateur est soumis aux
 * mêmes règles que tout le monde (plafond, créateur ≠ validateur).
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
            return "Vous n'avez pas la permission de valider les bons de commande.";
        }
        if ($commande->site_id === null || $commande->fournisseur_id === null) {
            return "Renseignez l'agence et le fournisseur de la commande avant de la valider.";
        }
        if ($commande->created_by !== null && $commande->created_by === $user->id) {
            return 'Vous avez créé ce bon de commande : il doit être validé par une autre personne.';
        }
        if ($commande->contenu_modifie_par !== null && $commande->contenu_modifie_par === $user->id) {
            return 'Vous avez modifié ce bon de commande en dernier : il doit être validé par une autre personne.';
        }

        return $this->plafonds->motifRefus($user, RegleValidationRole::DOMAINE_ACHATS, $commande->site_id, $this->montant($commande));
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
            $commande->load(['lignes', 'fournisseur.personne', 'fournisseur.entrepriseTierce', 'site']);

            if ($commande->lignes->isEmpty()) {
                throw ValidationException::withMessages(['validation' => 'La commande ne contient aucune ligne.']);
            }

            $refus = $this->motifNonValidable($commande, $user);
            if ($refus !== null) {
                throw ValidationException::withMessages(['validation' => $refus]);
            }

            $montant = $this->montant($commande);
            $regle = $this->plafonds->regleAppliquee($user, RegleValidationRole::DOMAINE_ACHATS, $commande->site_id, $montant);

            $this->figerLignes($commande);

            $commande->update([
                'statut' => StatutCommandeAchat::VALIDEE,
                'total_commande' => $montant,
                'montant_valide' => $montant,
                'validee_at' => now(),
                'validee_par' => $user->id,
                'fournisseur_nom_snapshot' => $commande->fournisseur?->nom_complet,
                'site_nom_snapshot' => $commande->site?->nom,
                'validation_regle_snapshot' => [
                    'role' => $regle->role_name,
                    'role_label' => Role::where('name', $regle->role_name)->value('label') ?: $regle->role_name,
                    'plafond' => $regle->plafond_illimite ? null : (float) $regle->plafond,
                    'plafond_illimite' => (bool) $regle->plafond_illimite,
                    'perimetre' => $regle->perimetre,
                    'sites' => $regle->sites,
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
