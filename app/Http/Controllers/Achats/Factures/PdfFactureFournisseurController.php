<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Models\Organization;
use App\Services\Achats\PerimetreCommandesAchat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Récapitulatif PDF d'une facture d'achat telle qu'enregistrée dans l'application (même modèle
 * visuel que le bon de commande). Ce n'est pas la facture originale du fournisseur.
 */
class PdfFactureFournisseurController extends Controller
{
    public function __invoke(FactureFournisseur $facture, PerimetreCommandesAchat $perimetre): Response
    {
        $this->authorize('view', $facture);
        $perimetre->autoriserFacture($facture, auth()->user());

        $facture->load([
            'commande', 'site', 'fournisseur.personne', 'fournisseur.entrepriseTierce', 'createdBy', 'valideePar',
            'lignes.receptionLigne.reception',
        ]);

        return Pdf::loadView('pdf.facture_achat', [
            'facture' => $facture,
            'organisation' => Organization::findOrFail($facture->organization_id),
        ])->setPaper('a4', 'portrait')->download($facture->reference.'.pdf');
    }
}
