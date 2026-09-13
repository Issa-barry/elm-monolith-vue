<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Support\Depenses\DepenseListingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportCsvDepenseController extends Controller
{
    public function __construct(
        private readonly DepenseListingService $listing,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'date_debut', 'date_fin', 'vehicule', 'concerne', 'telephone_concerne', 'montant']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        $depenses = $this->listing->query($filters, $orgId, $siteIds)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->get();

        [$labelCache, $vehiculeInfoCache] = $this->listing->preloadBeneficiaires($depenses->all());

        $filename = 'depenses-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($depenses, $labelCache, $vehiculeInfoCache) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Référence', 'Date', 'Type', 'Catégorie', 'Concerné', 'Téléphone concerné',
                'Véhicule', 'Montant (GNF)', 'Dépenses (GNF)', 'Statut', 'Site',
                'Saisi par', 'Validé par', 'Commentaire',
            ], ';');

            foreach ($depenses as $d) {
                $row = $this->listing->transform($d, $labelCache, $vehiculeInfoCache);
                fputcsv($handle, [
                    $d->id,
                    $row['date_depense'],
                    $row['type']['libelle'] ?? '',
                    $row['type']['categorie_label'] ?? '',
                    $row['beneficiaire_label'] ?? '',
                    $row['beneficiaire_telephone'] ?? '',
                    $row['vehicule_nom'] ?? '',
                    number_format((float) $row['montant'], 0, ',', ' '),
                    '0',
                    $d->statut->label(),
                    $row['site']['nom'] ?? '',
                    $row['user']['name'] ?? '',
                    $row['validateur']['name'] ?? '',
                    $row['commentaire'] ?? '',
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
