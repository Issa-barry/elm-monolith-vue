<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Site;
use App\Services\DroitCreationDepenseService;
use App\Support\Depenses\DepenseListingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexDepenseController extends Controller
{
    public function __construct(
        private readonly DepenseListingService $listing,
        private readonly DroitCreationDepenseService $droitCreationDepense,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Depense::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['search', 'type', 'statut', 'categorie', 'date_debut', 'date_fin', 'vehicule', 'concerne', 'telephone_concerne', 'montant']);
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));

        $paginator = $this->listing->query($filters, $orgId, $siteIds)
            ->with(['depenseType', 'site', 'user', 'validateur'])
            ->paginate(30)
            ->withQueryString();

        [$beneficiaireCache, $vehiculeInfoCache] = $this->listing->preloadBeneficiaires($paginator->items());

        $droitValidation = $this->droitCreationDepense->droitValidationPour($user, $orgId);

        $types = DepenseType::where('organization_id', $orgId)
            ->ordered()
            ->get(['id', 'libelle', 'categorie']);

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom']);

        $statsRow = $this->listing->query($filters, $orgId, $siteIds)
            ->reorder()
            ->selectRaw(
                'COUNT(*) as total,
                COALESCE(SUM(montant), 0) as montant_total,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as en_attente,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as validees',
                [StatutDepense::SOUMIS->value, StatutDepense::VALIDE->value]
            )
            ->first();

        return Inertia::render('Depenses/Index', [
            'depenses' => $paginator->through(fn (Depense $d) => $this->listing->transform($d, $beneficiaireCache, $vehiculeInfoCache, $user, $droitValidation)),
            'types' => $types->map(fn ($t) => ['id' => $t->id, 'libelle' => $t->libelle, 'categorie' => $t->categorie->value]),
            'sites' => $sites,
            'categories' => CategorieDepense::options(),
            'statuts' => StatutDepense::options(),
            'filters' => array_merge($filters, ['site_ids' => $siteIds]),
            'stats' => [
                'total' => (int) $statsRow->total,
                'montant_total' => (float) $statsRow->montant_total,
                'en_attente' => (int) $statsRow->en_attente,
                'validees' => (int) $statsRow->validees,
            ],
            'can_create' => $user->can('depenses.create'),
        ]);
    }
}
