<?php

namespace App\Http\Controllers\Achats;

use App\Enums\StatutCommandeAchat;
use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\ReceptionAchat;
use App\Models\RegleValidationRole;
use App\Services\Achats\CommandeAchatService;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, ValidationParPlafondService $plafonds, CommandeAchatService $service): Response
    {
        $this->authorize('view', $achat);

        $user = $request->user();
        $achat->load([
            'fournisseur.personne', 'fournisseur.entrepriseTierce', 'site:id,nom', 'lignes',
            'createdBy', 'valideePar', 'annuleePar', 'clotureePar',
            'receptions' => fn ($q) => $q->orderByDesc('date_reception')->orderByDesc('created_at'),
            'receptions.createdBy', 'receptions.lignes.commandeLigne',
        ]);

        $montant = (float) $achat->total_commande;
        $aValider = $achat->isAValider();
        $dejaRecu = (int) $achat->lignes->sum('qte_recue') > 0;

        // Bouton Valider : affiché seulement si le serveur accepterait (mêmes règles que
        // CommandeAchatService::valider()). Avec la permission mais une règle bloquante (plafond,
        // agence, créateur/modificateur), le motif est affiché à la place.
        $motifNonValidable = null;
        $peutValider = false;
        if ($aValider && $user->can('valider', $achat) && $user->checkPermissionTo('achats.valider')) {
            $motifNonValidable = $service->motifNonValidable($achat, $user);
            $peutValider = $motifNonValidable === null;
        }

        return Inertia::render('Achats/Show', [
            'commande' => [
                'id' => $achat->id,
                'reference' => $achat->reference,
                'statut' => $achat->statut?->value,
                'statut_label' => $achat->statut_label,
                'total_commande' => $montant,
                'montant_valide' => $achat->montant_valide !== null ? (float) $achat->montant_valide : null,
                'fournisseur_nom' => $achat->fournisseurNom(),
                'site_nom' => $achat->siteNom(),
                'note' => $achat->note,
                'created_at' => $achat->created_at?->format('d/m/Y'),
                'created_by' => $this->nom($achat->createdBy),
                'validee_at' => $achat->validee_at?->format('d/m/Y H:i'),
                'validee_par' => $this->nom($achat->valideePar),
                'validation_regle' => $achat->validation_regle_snapshot,
                'motif_annulation' => $achat->motif_annulation,
                'annulee_at' => $achat->annulee_at?->format('d/m/Y H:i'),
                'annulee_par' => $this->nom($achat->annuleePar),
                'motif_cloture' => $achat->motif_cloture,
                'cloturee_at' => $achat->cloturee_at?->format('d/m/Y H:i'),
                'cloturee_par' => $this->nom($achat->clotureePar),
                'is_a_valider' => $aValider,
                'lignes' => $achat->lignes->map(fn (CommandeAchatLigne $l) => [
                    'id' => $l->id,
                    'produit_nom' => $l->libelle_snapshot ?? '—',
                    'reference' => $l->reference_snapshot,
                    'qte' => (int) $l->qte,
                    'qte_recue' => (int) $l->qte_recue,
                    'reliquat' => $l->reliquat(),
                    'prix_achat_snapshot' => (float) $l->prix_achat_snapshot,
                    'total_ligne' => (float) $l->total_ligne,
                ])->values(),
                'receptions' => $achat->receptions->map(fn (ReceptionAchat $r) => [
                    'id' => $r->id,
                    'reference' => $r->reference,
                    'date_reception' => $r->date_reception?->format('d/m/Y'),
                    'note' => $r->note,
                    'created_by' => $this->nom($r->createdBy),
                    'lignes' => $r->lignes->map(fn ($rl) => [
                        'produit_nom' => $rl->commandeLigne?->libelle_snapshot ?? '—',
                        'qte_recue' => (int) $rl->qte_recue,
                        'cout_unitaire' => (float) $rl->cout_unitaire,
                    ])->values(),
                ])->values(),
            ],
            'actions' => [
                'peut_modifier' => $aValider && $user->can('update', $achat),
                'peut_valider' => $peutValider,
                'motif_non_validable' => $motifNonValidable,
                'peut_annuler' => ($aValider || $achat->statut === StatutCommandeAchat::VALIDEE) && ! $dejaRecu && $user->can('annuler', $achat),
                'peut_cloturer' => $achat->statut === StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE && $user->can('annuler', $achat),
                // La réception se fait uniquement dans Logistique → Réceptions : la fiche y renvoie.
                'lien_reception' => $achat->isReceptionnable() && $achat->site_id !== null && $user->can('receptionner', $achat)
                    ? route('logistique.receptions-fournisseurs.index', ['reference' => $achat->reference])
                    : null,
                'peut_supprimer' => $achat->isAnnulee() && $user->can('delete', $achat),
            ],
            'validable_par' => $aValider && $achat->site_id !== null
                ? $plafonds->rolesPouvantValider($achat->organization_id, RegleValidationRole::DOMAINE_ACHATS, 'achats.valider', $achat->site_id, $montant)
                : [],
        ]);
    }

    private function nom($user): ?string
    {
        return $user ? trim($user->prenom.' '.$user->nom) : null;
    }
}
