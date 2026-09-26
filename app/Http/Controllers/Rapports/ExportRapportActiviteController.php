<?php

namespace App\Http\Controllers\Rapports;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Rapports\Export\RapportActiviteExport;
use App\Services\Rapports\RapportActiviteService;
use App\Services\Rapports\RapportPerimetreResolver;
use App\Support\Rapports\RapportPerimetre;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export Excel / PDF du rapport d'activité (route `rapports.activite.export`) et de « Ma situation »
 * (route `ma-situation.export`). Le périmètre est résolu exactement comme à l'écran, à partir du
 * nom de route (jamais d'un paramètre client) : un export ne voit jamais plus que l'écran.
 */
class ExportRapportActiviteController extends Controller
{
    public function __invoke(Request $request, RapportPerimetreResolver $perimetres, RapportActiviteService $rapports): Response
    {
        $user = $request->user();
        $maSituation = $request->route()?->getName() === 'ma-situation.export';
        $perimetre = $maSituation
            ? $perimetres->pourMaSituation($user, $request)
            : $perimetres->pourRapport($user, $request);

        $rapport = $rapports->rapport($perimetre, null);
        $entete = $this->entete($user, $perimetre);
        $nom = ($maSituation ? 'ma-situation' : 'rapport-activite').'-'.$perimetre->debut()->format('Y-m-d')
            .($perimetre->debut()->isSameDay($perimetre->fin()) ? '' : '-au-'.$perimetre->fin()->format('Y-m-d'));

        if ($request->input('format') === 'pdf') {
            return Pdf::loadView('pdf.rapports.activite', [
                'title' => $maSituation ? 'Ma situation' : "Rapport d'activité",
                'org' => Organization::find($user->organization_id),
                'entete' => $entete,
                'rapport' => $rapport,
                'printed_by' => $user->name ?: '—',
                'generated_at' => now(),
            ])->setPaper('a4', 'landscape')->download($nom.'.pdf');
        }

        return Excel::download(new RapportActiviteExport($rapport, $entete), $nom.'.xlsx', ExcelFormat::XLSX);
    }

    /**
     * @return array<string, string>
     */
    private function entete(User $user, RapportPerimetre $p): array
    {
        $agences = $p->siteIds === null
            ? 'Toutes les agences'
            : (Site::whereIn('id', $p->siteIds)->orderBy('nom')->pluck('nom')->implode(', ') ?: 'Aucune agence');

        $agent = match (true) {
            $p->agentId === null => 'Tous les agents',
            $p->agentId === $user->id => $user->name,
            default => User::with('personne')->find($p->agentId)?->name ?? '—',
        };

        return [
            'Périmètre' => $p->maSituation ? 'Ma situation' : "Rapport d'activité",
            'Agences' => $agences,
            'Agent' => $agent,
            'Période' => $p->periode->libelle(),
            'Généré le' => now()->format('d/m/Y H:i'),
        ];
    }
}
