<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\Organization;
use App\Support\Depenses\DepenseListingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImprimerDepenseController extends Controller
{
    public function __construct(
        private readonly DepenseListingService $listing,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'date_debut', 'date_fin', 'vehicule', 'concerne', 'telephone_concerne', 'montant']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $org = Organization::find($orgId);
        $printedBy = $user->name;
        $now = now();

        $depenses = $this->listing->query($filters, $orgId, $siteIds)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->get();

        [$labelCache, $vehiculeInfoCache] = $this->listing->preloadBeneficiaires($depenses->all());
        $rows = $depenses->map(fn (Depense $d) => $this->listing->transform($d, $labelCache, $vehiculeInfoCache));

        $grouped = $rows->groupBy(fn ($row) => $row['site']['id'] ?? 'sans-site');

        $sites = $grouped->map(fn ($siteRows) => [
            'site_nom' => $siteRows->first()['site']['nom'] ?? null,
            'rows' => $siteRows,
            'total' => $siteRows->sum('montant'),
        ])->values();

        return response()->view('print.depenses', [
            'sites' => $sites,
            'filters' => $filters,
            'org' => $org,
            'printed_by' => $printedBy,
            'generated_at' => $now,
        ]);
    }
}
