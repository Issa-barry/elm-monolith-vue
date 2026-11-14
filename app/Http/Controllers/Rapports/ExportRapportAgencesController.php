<?php

namespace App\Http\Controllers\Rapports;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Rapports\Export\RapportAgencesExport;
use App\Services\Rapports\RapportAgencesService;
use App\Services\Rapports\RapportPerimetreResolver;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export Excel du rapport par agence (route `rapports.agences.export`) : même périmètre et même
 * période que l'écran, résolus par RapportPerimetreResolver — un export ne voit jamais plus.
 */
class ExportRapportAgencesController extends Controller
{
    public function __invoke(Request $request, RapportPerimetreResolver $perimetres, RapportAgencesService $rapports): Response
    {
        $p = $perimetres->pourRapportAgences($request->user(), $request);

        $entete = [
            'Rapport' => 'Rapport par agence',
            'Agences' => $p->siteIds === null
                ? 'Toutes les agences'
                : (Site::whereIn('id', $p->siteIds)->orderBy('nom')->pluck('nom')->implode(', ') ?: 'Aucune agence'),
            'Période' => $p->periode->libelle(),
            'Généré le' => now()->format('d/m/Y H:i'),
        ];
        $nom = 'rapport-agences-'.$p->debut()->format('Y-m-d')
            .($p->debut()->isSameDay($p->fin()) ? '' : '-au-'.$p->fin()->format('Y-m-d'));

        return Excel::download(new RapportAgencesExport($rapports->rapport($p), $entete), $nom.'.xlsx', ExcelFormat::XLSX);
    }
}
