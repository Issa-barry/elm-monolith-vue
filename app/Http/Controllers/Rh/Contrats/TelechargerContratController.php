<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Models\Organization;
use App\Services\SiteScopeService;
use App\Support\Documents\ContratDocument;
use App\Support\Documents\DocumentPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Récapitulatif PDF d'un contrat (docs/rh-contrat-pdf.md) : aperçu dans le navigateur avec
 * `?preview=1`, sinon téléchargement. Même accès que la liste des contrats : `rh-contrats.read`,
 * même organisation, et agence actuelle du salarié dans le périmètre de consultation (sauf vision
 * de toutes les agences). Organisation et agence vérifiées explicitement : le Gate::before du super
 * administrateur court-circuite les policies. Le salaire n'apparaît qu'avec `rh-remunerations.read`.
 */
class TelechargerContratController extends Controller
{
    public function __invoke(Request $request, Contrat $contrat, SiteScopeService $perimetre): Response
    {
        $user = $request->user();

        abort_unless($contrat->organization_id === $user->organization_id, 403);
        $this->authorize('view', $contrat);

        ContratDocument::chargerEmploye($contrat);

        if (! $user->voitToutesLesAgences()) {
            $siteId = $contrat->employe?->site_id;
            abort_unless($siteId !== null && $perimetre->accessibleSiteIds($user)->contains($siteId), 403);
        }

        $document = ContratDocument::depuis(
            $contrat,
            Organization::findOrFail($contrat->organization_id),
            $user->can('rh-remunerations.read'),
        );

        return DocumentPdfRenderer::reponse($document, $request->boolean('preview'));
    }
}
