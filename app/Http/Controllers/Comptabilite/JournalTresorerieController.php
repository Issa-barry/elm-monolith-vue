<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\CategorieJournal;
use App\Enums\SensJournal;
use App\Http\Controllers\Controller;
use App\Models\JournalTresorerie;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Services\PeriodePaiementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalTresorerieController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaiementPeriode::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filters = $request->only(['sens', 'categorie', 'annee', 'mois', 'quinzaine', 'search']);
        $isAdmin = $user->isAdmin();
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $query = JournalTresorerie::forOrg($orgId)->with('site');

        if (! $isAdmin) {
            $siteIds = $user->sites()->pluck('sites.id')->all();
            $query->whereIn('site_id', $siteIds);
        } elseif (! empty($filtreSiteIds)) {
            $query->whereIn('site_id', $filtreSiteIds);
        }

        if (! empty($filters['sens'])) {
            $query->where('sens', $filters['sens']);
        }
        if (! empty($filters['categorie'])) {
            $query->where('categorie', $filters['categorie']);
        }
        if (! empty($filters['annee'])) {
            $annee = (int) $filters['annee'];
            $query->whereBetween('date_operation', ["{$annee}-01-01", "{$annee}-12-31"]);
        }
        if (! empty($filters['mois'])) {
            $query->whereMonth('date_operation', (int) $filters['mois']);
        }
        if (! empty($filters['quinzaine']) && in_array($filters['quinzaine'], [PeriodePaiementService::P1, PeriodePaiementService::P2], true)) {
            if ($filters['quinzaine'] === PeriodePaiementService::P1) {
                $query->whereDay('date_operation', '<=', 15);
            } else {
                $query->whereDay('date_operation', '>', 15);
            }
        }

        $totalEntrees = (float) (clone $query)->where('sens', SensJournal::ENTREE->value)->sum('montant');
        $totalSorties = (float) (clone $query)->where('sens', SensJournal::SORTIE->value)->sum('montant');

        if (! empty($filters['search'])) {
            $s = mb_strtolower(trim($filters['search']));
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(reference) LIKE ?', ["%{$s}%"])
                ->orWhereRaw('LOWER(libelle) LIKE ?', ["%{$s}%"])
            );
        }

        $lignes = $query
            ->orderByDesc('date_operation')
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (JournalTresorerie $j) => [
                'id' => $j->id,
                'date_operation' => $j->date_operation?->toDateString(),
                'sens' => $j->sens?->value,
                'categorie' => $j->categorie?->value,
                'categorie_label' => $j->categorie?->label(),
                'libelle' => $j->libelle,
                'reference' => $j->reference,
                'montant' => (float) $j->montant,
                'site' => $j->site ? ['id' => $j->site->id, 'nom' => $j->site->nom] : null,
            ]);

        return Inertia::render('Comptabilite/Journal', [
            'lignes' => $lignes,
            'sens_options' => SensJournal::options(),
            'categories' => CategorieJournal::options(),
            'sites' => $isAdmin
                ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
                : collect(),
            'is_admin' => $isAdmin,
            'filters' => array_merge($filters, ['site_ids' => $filtreSiteIds]),
            'kpis' => [
                'total_entrees' => $totalEntrees,
                'total_sorties' => $totalSorties,
                'solde' => $totalEntrees - $totalSorties,
            ],
        ]);
    }
}
