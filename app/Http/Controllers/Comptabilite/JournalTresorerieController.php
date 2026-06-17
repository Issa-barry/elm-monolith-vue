<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\CategorieJournal;
use App\Enums\SensJournal;
use App\Http\Controllers\Controller;
use App\Models\JournalTresorerie;
use App\Models\PaiementPeriode;
use App\Models\Site;
use Carbon\Carbon;
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
        $filters = $request->only(['sens', 'categorie', 'date_from', 'date_to', 'site_id']);

        $query = JournalTresorerie::forOrg($orgId)->with('site');

        if (! $user->isAdmin()) {
            $siteIds = $user->sites()->pluck('sites.id')->all();
            $query->whereIn('site_id', $siteIds);
        } elseif (! empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        if (! empty($filters['sens'])) {
            $query->where('sens', $filters['sens']);
        }
        if (! empty($filters['categorie'])) {
            $query->where('categorie', $filters['categorie']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('date_operation', '>=', Carbon::parse($filters['date_from']));
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('date_operation', '<=', Carbon::parse($filters['date_to']));
        }

        $totalEntrees = (float) (clone $query)->where('sens', SensJournal::ENTREE->value)->sum('montant');
        $totalSorties = (float) (clone $query)->where('sens', SensJournal::SORTIE->value)->sum('montant');

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
            'sites' => $user->isAdmin()
                ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
                : collect(),
            'is_admin' => $user->isAdmin(),
            'filters' => $filters,
            'kpis' => [
                'total_entrees' => $totalEntrees,
                'total_sorties' => $totalSorties,
                'solde' => $totalEntrees - $totalSorties,
            ],
        ]);
    }
}
