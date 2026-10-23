<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Services\SiteScopeService;
use App\Support\Documents\BonCommandeVenteDocument;
use App\Support\Documents\DocumentPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Télécharge le bon de commande de vente (modèle officiel partagé des factures et bons), quel que
 * soit le statut de la commande. Même accès que la fiche de la commande : permission de
 * consultation des ventes, même organisation et agence de la commande dans le périmètre de
 * consultation (SiteScopeService). Organisation et agence vérifiées explicitement : le
 * Gate::before du super administrateur court-circuite les policies.
 */
class TelechargerBonCommandeVenteController extends Controller
{
    public function __invoke(Request $request, CommandeVente $commande_vente, SiteScopeService $perimetre): Response
    {
        $user = $request->user();

        abort_unless($commande_vente->organization_id === $user->organization_id, 403);
        $this->authorize('view', $commande_vente);

        if (! $user->voitToutesLesAgences()) {
            abort_unless($perimetre->accessibleSiteIds($user)->contains($commande_vente->site_id), 403);
        }

        $document = BonCommandeVenteDocument::depuis($commande_vente, Organization::findOrFail($commande_vente->organization_id));

        return DocumentPdfRenderer::reponse($document, $request->boolean('preview'));
    }
}
