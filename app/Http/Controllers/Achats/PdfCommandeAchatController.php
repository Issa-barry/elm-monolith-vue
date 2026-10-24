<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Support\Documents\BonCommandeAchatDocument;
use App\Support\Documents\DocumentPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdfCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat): Response
    {
        $this->authorize('view', $achat);
        app(PerimetreCommandesAchat::class)->autoriserConsultation($achat, auth()->user());

        $achat->load(['fournisseur', 'lignes.variante.produit', 'createdBy', 'valideePar', 'organization', 'site', 'sitePayeur']);

        return DocumentPdfRenderer::reponse(BonCommandeAchatDocument::depuis($achat, $achat->organization), $request->boolean('preview'));
    }
}
