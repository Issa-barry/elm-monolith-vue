<?php

namespace App\Http\Controllers\Achats;

use App\Enums\StatutCommandeAchat;
use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\FactureFournisseur;
use App\Models\ReceptionAchat;
use App\Models\RegleValidationRole;
use App\Policies\CommandeAchatPolicy;
use App\Services\Achats\CommandeAchatService;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Validation\ValidationParPlafondService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, ValidationParPlafondService $plafonds, CommandeAchatService $service, FactureFournisseurService $factures): Response
    {
        $this->authorize('view', $achat);
        app(PerimetreCommandesAchat::class)->autoriser($achat, auth()->user());

        $user = $request->user();
        $achat->load([
            'fournisseur.personne', 'fournisseur.entrepriseTierce', 'site:id,nom', 'lignes',
            'createdBy', 'valideePar', 'annuleePar', 'clotureePar',
            'receptions' => fn ($q) => $q->orderByDesc('date_reception')->orderByDesc('created_at'),
            'receptions.createdBy', 'receptions.lignes.commandeLigne',
            'factures' => fn ($q) => $q->orderByDesc('date_facture')->orderByDesc('created_at'),
        ]);

        $montant = (float) $achat->total_commande;
        $aValider = $achat->isAValider();
        $dejaRecu = (int) $achat->lignes->sum('qte_recue') > 0;

        // Bouton Valider : affiché seulement si le serveur accepterait (mêmes règles que
        // CommandeAchatService::valider()). Sinon le motif est affiché à la place — y compris
        // l'absence de `achats.valider` sur le rôle quand le Gate::before du super administrateur
        // fait passer can() : sans motif, le bouton disparaissait sans explication.
        $motifNonValidable = null;
        $peutValider = false;
        if ($aValider && $user->can('valider', $achat)) {
            $motifNonValidable = $service->motifNonValidable($achat, $user);
            $peutValider = $motifNonValidable === null;
        }

        // Saisie d'une facture : permission, bon validé dans le périmètre, et au moins une quantité
        // reçue non encore facturée (FactureFournisseurService::lignesFacturables(), seule source).
        $peutFacturer = $achat->validee_at !== null
            && ! $achat->isAnnulee()
            && $user->can('create', FactureFournisseur::class)
            && app(PerimetreCommandesAchat::class)->couvreSite($user, $achat->site_id)
            && $factures->lignesFacturables($achat)->sum('facturable') > 0;

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
                'factures' => $user->can('factures-fournisseurs.read') ? $achat->factures->map(fn (FactureFournisseur $f) => [
                    'id' => $f->id,
                    'reference' => $f->reference,
                    'numero_facture_fournisseur' => $f->numero_facture_fournisseur,
                    'date_facture' => $f->date_facture?->format('d/m/Y'),
                    'montant_ttc' => (float) $f->montant_ttc,
                    'reste_du' => $f->resteDu(),
                    'statut' => $f->statut?->value,
                    'statut_label' => $f->statut?->label(),
                ])->values() : [],
            ],
            'actions' => [
                'peut_modifier' => $aValider && $user->can('update', $achat),
                'peut_valider' => $peutValider,
                'motif_non_validable' => $motifNonValidable,
                'peut_annuler' => ($aValider || $achat->statut === StatutCommandeAchat::VALIDEE) && ! $dejaRecu && $user->can('annuler', $achat),
                'peut_cloturer' => $achat->statut === StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE && $user->can('annuler', $achat),
                // La réception se fait uniquement dans Logistique → Réceptions : la fiche y renvoie.
                'lien_reception' => $achat->isReceptionnable() && $user->can('receptionner', $achat) && CommandeAchatPolicy::estRattacheAgence($user, $achat)
                    ? route('logistique.receptions-fournisseurs.index', ['reference' => $achat->reference])
                    : null,
                'peut_supprimer' => $achat->isAnnulee() && $user->can('delete', $achat),
                'lien_facture' => $peutFacturer ? route('achats.factures.create', ['commande' => $achat->id]) : null,
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
