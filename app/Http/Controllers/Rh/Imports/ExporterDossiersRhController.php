<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\ImportRh;
use App\Models\ServiceRh;
use App\Services\ImportRh\ExportDossiersRhMajExport;
use App\Services\SiteScopeService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * « Exporter pour mise à jour » : fichier pré-rempli avec les salariés que l'utilisateur peut
 * modifier (organisation et agences d'écriture), réimportable tel quel. Salaire seulement avec
 * `rh-remunerations.read`.
 */
class ExporterDossiersRhController extends Controller
{
    public function __invoke(SiteScopeService $siteScope): BinaryFileResponse
    {
        $this->authorize('create', ImportRh::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $sites = $siteScope->assignedSiteIds($user);

        $employes = Employe::with(['personne', 'site:id,nom', 'service:id,libelle', 'fonction:id,libelle', 'contratActif'])
            ->where('organization_id', $orgId)
            ->when($sites->isNotEmpty(), fn ($q) => $q->whereIn('site_id', $sites))
            ->orderBy('matricule')
            ->get();

        $actifs = fn (string $modele) => $modele::where('organization_id', $orgId)->where('is_active', true)->orderBy('libelle')->get();

        return Excel::download(
            new ExportDossiersRhMajExport($employes, $actifs(ServiceRh::class), $actifs(FonctionRh::class), $user->can('rh-remunerations.read')),
            'dossiers-rh-mise-a-jour-'.now()->format('Y-m-d').'.xlsx',
        );
    }
}
