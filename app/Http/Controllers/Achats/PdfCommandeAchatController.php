<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\PerimetreCommandesAchat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PdfCommandeAchatController extends Controller
{
    public function __invoke(CommandeAchat $achat): Response
    {
        $this->authorize('view', $achat);
        app(PerimetreCommandesAchat::class)->autoriserConsultation($achat, auth()->user());

        $achat->load(['fournisseur', 'lignes.variante.produit', 'createdBy', 'valideePar', 'organization', 'site', 'sitePayeur']);

        $createdBy = $achat->createdBy
            ? trim($achat->createdBy->prenom.' '.$achat->createdBy->nom)
            : 'â€”';

        $pdf = Pdf::loadView('pdf.bon_commande_achat', [
            'commande' => $achat,
            'organisation' => $achat->organization,
            'createdBy' => $createdBy,
        ])->setPaper('a4', 'portrait');

        return $pdf->download($achat->reference.'.pdf');
    }
}
