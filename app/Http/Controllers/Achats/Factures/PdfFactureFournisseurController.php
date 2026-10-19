<?php

namespace App\Http\Controllers\Achats\Factures;

use App\Http\Controllers\Controller;
use App\Models\FactureFournisseur;
use App\Models\Organization;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Support\Documents\DocumentPdfRenderer;
use App\Support\Documents\FactureAchatDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Récapitulatif PDF d'une facture d'achat telle qu'enregistrée dans l'application (modèle officiel
 * partagé des factures et bons). Ce n'est pas la facture originale du fournisseur.
 */
class PdfFactureFournisseurController extends Controller
{
    public function __invoke(Request $request, FactureFournisseur $facture, PerimetreCommandesAchat $perimetre): Response
    {
        $this->authorize('view', $facture);
        $perimetre->autoriserConsultationFacture($facture, auth()->user());

        $facture->load(['site', 'fournisseur.personne', 'fournisseur.entrepriseTierce', 'lignes']);

        $document = FactureAchatDocument::depuis($facture, Organization::findOrFail($facture->organization_id));

        return DocumentPdfRenderer::reponse($document, $request->boolean('preview'));
    }
}
