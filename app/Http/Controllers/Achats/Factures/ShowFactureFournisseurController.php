<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Enums\StatutFactureFournisseur;
use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
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
    ): Response {
        $this->authorize('view', $facture);
        $user = $request->user();
        $perimetre->autoriserFacture($facture, $user);

        $facture->load('commande');

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
            'actions' => [
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
