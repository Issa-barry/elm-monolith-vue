<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Services\SiteScopeService;
use App\Support\Documents\DocumentPdfRenderer;
use App\Support\Documents\FactureVenteDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Télécharge le PDF d'une facture de vente (modèle officiel partagé des factures et bons), quel
 * que soit son statut : une facture payée ou annulée reste téléchargeable. Même accès que la liste
 * /backoffice/factures : permission de consultation des ventes, même organisation et agence de la
 * commande dans le périmètre de consultation (SiteScopeService). Organisation et agence vérifiées
 * explicitement : le Gate::before du super administrateur court-circuite les policies.
 */
class TelechargerFactureVenteController extends Controller
{
    public function __invoke(Request $request, FactureVente $facture_vente, SiteScopeService $perimetre): Response
    {
        $user = $request->user();
        $facture_vente->load('commande');
        $commande = $facture_vente->commande;

        abort_unless($commande && $facture_vente->organization_id === $user->organization_id, 403);
        $this->authorize('view', $commande);

        if (! $user->voitToutesLesAgences()) {
            abort_unless($perimetre->accessibleSiteIds($user)->contains($commande->site_id), 403);
        }

        $document = FactureVenteDocument::depuis($facture_vente, Organization::findOrFail($facture_vente->organization_id));

        return DocumentPdfRenderer::reponse($document, $request->boolean('preview'));
    }
}
