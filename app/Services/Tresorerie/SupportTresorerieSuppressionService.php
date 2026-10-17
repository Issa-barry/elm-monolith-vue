<?php

namespace App\Services\Tresorerie;

use App\Enums\AuditEvent;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\DepenseType;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\MouvementFonds;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementFournisseur;
use App\Models\RemboursementVente;
use App\Models\SoldeOuvertureTresorerie;
use App\Models\TiersComptable;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suppression définitive d'un support de trésorerie resté en BROUILLON (ADR 0028).
 *
 * Seul un brouillon se supprime : il n'a jamais été validé, donc jamais utilisable (le modèle
 * interdit de l'activer hors validation). Un support validé — actif ou désactivé — a pu servir :
 * il se désactive, son historique et son compte comptable restent consultables.
 *
 * Par prudence, toute opération qui référencerait malgré tout le brouillon (mouvement, encaissement,
 * paiement, remboursement, solde d'ouverture) bloque aussi la suppression. Le sous-compte créé
 * automatiquement pour un support dédié à un agent (571xxx, sous-compte d'opérateur) est supprimé
 * avec lui s'il n'a jamais servi ; un compte du plan comptable partagé (571000, 521000...) n'est
 * jamais touché.
 *
 * La suppression est tracée dans le journal d'audit (qui, quand, motif et fiche du support), la
 * ligne elle-même disparaissant.
 */
class SupportTresorerieSuppressionService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /** @throws ValidationException si le support a été validé ou est référencé par une opération */
    public function supprimer(CompteTresorerie $support, User $par, ?string $motif = null): void
    {
        DB::transaction(function () use ($support, $par, $motif) {
            // Verrou : une validation concurrente (qui verrouille la même ligne) passe avant ou après,
            // jamais pendant — un support validé entre-temps est donc vu validé ici.
            $verrouille = CompteTresorerie::forOrg($par->organization_id)
                ->with(['site:id,nom', 'agent.personne', 'compte:id,numero'])
                ->whereKey($support->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouille->estValide()) {
                throw ValidationException::withMessages([
                    'statut' => "« {$verrouille->libelle} » a été validé : il ne peut plus être supprimé. Désactivez-le, son historique reste consultable.",
                ]);
            }

            if ($this->estReference($verrouille)) {
                throw ValidationException::withMessages([
                    'statut' => "« {$verrouille->libelle} » est déjà lié à des opérations de trésorerie : il ne peut pas être supprimé.",
                ]);
            }

            $this->audit->record($verrouille, AuditEvent::DELETED, $par, [
                'libelle' => $verrouille->libelle,
                'type' => $verrouille->type->value,
                'site' => $verrouille->site?->nom,
                'agent' => $verrouille->agent?->name,
                'operateur_mobile_money' => $verrouille->operateur_mobile_money?->value,
                'compte_numero' => $verrouille->compte?->numero,
                'statut' => $verrouille->statut()->value,
            ], null, array_filter(['motif' => $motif !== null ? trim($motif) : null]));

            $compteId = $verrouille->compte_comptable_id;
            $dedie = $verrouille->isDediee();

            $verrouille->delete();

            if ($dedie) {
                $this->supprimerSousCompteInutilise($verrouille->organization_id, $compteId);
            }
        });
    }

    private function estReference(CompteTresorerie $support): bool
    {
        return SoldeOuvertureTresorerie::where('compte_tresorerie_id', $support->id)->exists()
            || MouvementFonds::where('compte_tresorerie_origine_id', $support->id)
                ->orWhere('compte_tresorerie_destination_id', $support->id)
                ->exists()
            || EncaissementVente::where('compte_tresorerie_id', $support->id)->exists()
            || PaiementFichePaiement::where('compte_tresorerie_id', $support->id)->exists()
            || PaiementFournisseur::where('compte_tresorerie_id', $support->id)->exists()
            || RemboursementVente::where('compte_tresorerie_id', $support->id)->exists();
    }

    /**
     * Le sous-compte propre d'un support dédié n'a de sens qu'avec lui : il est supprimé s'il ne
     * porte aucune écriture et n'est référencé nulle part (autre support, sous-compte, paramétrage,
     * type de dépense, tiers). Sinon il reste au plan comptable — un compte déjà utilisé n'est jamais supprimé.
     */
    private function supprimerSousCompteInutilise(string $organizationId, string $compteId): void
    {
        $utilise = EcritureComptable::where('compte_comptable_id', $compteId)->exists()
            || CompteTresorerie::where('compte_comptable_id', $compteId)->exists()
            || CompteComptable::where('parent_id', $compteId)->exists()
            || CompteMapping::where('compte_comptable_id', $compteId)->exists()
            || DepenseType::where('compte_comptable_id', $compteId)->exists()
            || TiersComptable::where('compte_collectif_id', $compteId)->exists();

        if (! $utilise) {
            CompteComptable::where('organization_id', $organizationId)->whereKey($compteId)->delete();
        }
    }
}
