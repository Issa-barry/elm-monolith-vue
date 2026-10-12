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
    ): Response {
        $this->authorize('view', $facture);
        $user = $request->user();
        $perimetre->autoriserConsultationFacture($facture, $user);
        // Fiche ouverte en simple consultation (vision 360°, ADR 0025) : aucune action d'écriture hors
        // du périmètre d'achat — contrôle explicite (Gate::before du super administrateur).
        $dansPerimetre = $perimetre->factureVisible($facture, $user);

        $facture->load(['commande', 'site:id,nom', 'paiements' => fn ($q) => $q->orderByDesc('date_paiement')->orderByDesc('created_at'), 'paiements.compteTresorerie', 'paiements.createdBy', 'paiements.site:id,nom']);

        // Bouton Payer : seulement si le serveur accepterait (mêmes règles que
        // PaiementFournisseurService::payer()). Le dialogue propose l'agence de la facture par
        // défaut, et les autres agences du périmètre d'achat du payeur où un paiement est possible
        // (moyens et caisse dédiée de chacune, comme pour les fiches).
        $motifNonPayable = null;
        $peutPayer = false;
        $optionsPaiement = null;
        if ($dansPerimetre && $facture->isConstatee() && $facture->resteDu() > 0 && $user->can('payer', $facture) && $user->checkPermissionTo('factures-fournisseurs.payer')) {
            $motifNonPayable = $paiements->motifNonPayable($facture, $user);
            $peutPayer = $motifNonPayable === null;
            if ($peutPayer) {
                $agences = $paiements->agencesDePaiement($facture, $user);
                $prevue = collect($agences)->firstWhere('site_id', $facture->site_id);
                $optionsPaiement = [
                    'moyens' => $prevue['moyens'] ?? [],
                    'especes_disponibles' => $prevue['peut_encaisser_especes'] ?? false,
                    'solde_especes' => $prevue['solde_especes'] ?? null,
                    'agences' => $agences,
                    'agence_facture' => ['id' => $facture->site_id, 'nom' => $facture->site?->nom ?? '—'],
                ];
            }
        }

        // Bouton Valider : seulement si le serveur accepterait ; avec la permission mais une règle
        // bloquante (périmètre, auteur, dernier modificateur), le motif est affiché à la place.
        $motifNonValidable = null;
        $peutValider = false;
        if ($dansPerimetre && $facture->isBrouillon() && $user->can('valider', $facture) && $user->checkPermissionTo('factures-fournisseurs.valider')) {
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
                // Renseignée seulement quand l'argent est sorti d'une autre agence que celle de la facture.
                'autre_agence_nom' => $p->site_id !== $facture->site_id ? $p->site?->nom : null,
                'reference_paiement' => $p->reference_paiement,
                'created_by' => $p->createdBy ? trim($p->createdBy->prenom.' '.$p->createdBy->nom) : null,
            ])->values(),
            'paiement' => $optionsPaiement,
            'actions' => [
                'peut_payer' => $peutPayer,
                'motif_non_payable' => $motifNonPayable,
                'peut_modifier' => $dansPerimetre && $facture->isBrouillon() && $user->can('update', $facture),
                'peut_valider' => $peutValider,
                'motif_non_validable' => $motifNonValidable,
                'peut_annuler' => $dansPerimetre
                    && in_array($facture->statut, [StatutFactureFournisseur::BROUILLON, StatutFactureFournisseur::VALIDEE], true)
                    && (float) $facture->montant_paye === 0.0
                    && $user->can('annuler', $facture),
                'peut_relancer_comptabilite' => $dansPerimetre && $facture->isConstatee() && ! $piece && $user->can('valider', $facture),
            ],
        ]);
    }
}
