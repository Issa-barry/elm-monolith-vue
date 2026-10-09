<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Enums\StatutFactureFournisseur;
use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Models\PaiementFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PaiementFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
use App\Services\Tresorerie\DecaissementSupportResolver;
use App\Support\Achats\FactureFournisseurPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowFactureFournisseurController extends Controller
{
    public function __invoke(
        Request $request,
        FactureFournisseur $facture,
        PerimetreCommandesAchat $perimetre,
        FactureFournisseurService $service,
        FactureFournisseurPresenter $presenter,
        FactureFournisseurComptabilisationService $comptabilisation,
        PaiementFournisseurService $paiements,
        DecaissementSupportResolver $supports,
    ): Response {
        $this->authorize('view', $facture);
        $user = $request->user();
        $perimetre->autoriserFacture($facture, $user);

        $facture->load(['commande', 'paiements' => fn ($q) => $q->orderByDesc('date_paiement')->orderByDesc('created_at'), 'paiements.compteTresorerie', 'paiements.createdBy']);

        // Bouton Payer : seulement si le serveur accepterait (mêmes règles que
        // PaiementFournisseurService::payer()) ; options du dialogue = moyens et caisse de l'AGENCE
        // DE LA FACTURE (DecaissementSupportResolver, comme pour les fiches).
        $motifNonPayable = null;
        $peutPayer = false;
        $optionsPaiement = null;
        if ($facture->isConstatee() && $facture->resteDu() > 0 && $user->can('payer', $facture) && $user->checkPermissionTo('factures-fournisseurs.payer')) {
            $motifNonPayable = $paiements->motifNonPayable($facture, $user);
            $peutPayer = $motifNonPayable === null;
            if ($peutPayer) {
                $optionsPaiement = $supports->optionsPourSites($facture->organization_id, [$facture->site_id], $user)[$facture->site_id] ?? null;
            }
        }

        // Bouton Valider : seulement si le serveur accepterait ; avec la permission mais une règle
        // bloquante (périmètre, auteur, dernier modificateur), le motif est affiché à la place.
        $motifNonValidable = null;
        $peutValider = false;
        if ($facture->isBrouillon() && $user->can('valider', $facture) && $user->checkPermissionTo('factures-fournisseurs.valider')) {
            $motifNonValidable = $service->motifNonValidable($facture, $user);
            $peutValider = $motifNonValidable === null;
        }

        $piece = $comptabilisation->pieceDe($facture);
        $etatComptable = $comptabilisation->etat($facture, $piece, pieceConnue: true);

        return Inertia::render('Achats/Factures/Show', [
            'facture' => $presenter->facture($facture),
            'commande' => $presenter->commande($facture->commande),
            'comptabilite' => $etatComptable + [
                'erreur' => $etatComptable['statut'] === 'en_attente' ? $facture->comptabilisation_erreur : null,
            ],
            'paiements' => $facture->paiements->map(fn (PaiementFournisseur $p) => [
                'id' => $p->id,
                'date_paiement' => $p->date_paiement?->format('d/m/Y'),
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement,
                'support' => $p->compteTresorerie?->libelle,
                'reference_paiement' => $p->reference_paiement,
                'created_by' => $p->createdBy ? trim($p->createdBy->prenom.' '.$p->createdBy->nom) : null,
            ])->values(),
            'paiement' => $optionsPaiement,
            'actions' => [
                'peut_payer' => $peutPayer,
                'motif_non_payable' => $motifNonPayable,
                'peut_modifier' => $facture->isBrouillon() && $user->can('update', $facture),
                'peut_valider' => $peutValider,
                'motif_non_validable' => $motifNonValidable,
                'peut_annuler' => in_array($facture->statut, [StatutFactureFournisseur::BROUILLON, StatutFactureFournisseur::VALIDEE], true)
                    && (float) $facture->montant_paye === 0.0
                    && $user->can('annuler', $facture),
                'peut_relancer_comptabilite' => $facture->isConstatee() && ! $piece && $user->can('valider', $facture),
            ],
        ]);
    }
}
