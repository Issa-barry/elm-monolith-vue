<?php

namespace App\Http\Controllers\Rapports;

use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Services\ModuleService;
use App\Services\Rapports\RapportAgencesService;
use App\Services\Rapports\RapportPerimetreResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rapport d'activité par agence (`rapports.read`, docs/rapports.md) : une ligne par agence du
 * périmètre (RapportPerimetreResolver), calculs dans RapportAgencesService.
 */
class IndexRapportAgencesController extends Controller
{
    public function __invoke(Request $request, RapportPerimetreResolver $perimetres, RapportAgencesService $rapports): Response
    {
        $user = $request->user();
        $p = $perimetres->pourRapportAgences($user, $request);

        return Inertia::render('Rapports/Agences', [
            'url' => '/backoffice/rapports/agences',
            'export_url' => '/backoffice/rapports/agences/export',
            'filters' => [
                ...$p->periode->valeursFiltre('periode', RapportPerimetreResolver::PERIODES),
                'site_ids' => $p->siteIds ?? [],
            ],
            'periode' => [
                ...$p->periode->pourFront(RapportPerimetreResolver::PERIODES),
                'libelle' => $p->periode->libelle(),
            ],
            'sites' => $perimetres->sitesProposes($user)->all(),
            'rapport' => $rapports->rapport($p),
            // Détail des remises (Comptabilité → Trésorerie → Remises des agences) : mêmes conditions
            // que sa route (module Comptabilité, `tresorerie.read`). Les agences du rapport sont
            // toujours visibles dans cette vue (périmètre au moins aussi large).
            'peut_voir_remises' => $user->can('tresorerie.read')
                && $user->organization !== null
                && ModuleService::isActive(ModuleFeature::COMPTABILITE, $user->organization),
        ]);
    }
}
