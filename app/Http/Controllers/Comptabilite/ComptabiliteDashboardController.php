<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\SensJournal;
use App\Http\Controllers\Controller;
use App\Models\JournalTresorerie;
use App\Models\PaiementFiche;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComptabiliteDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\PaiementPeriode::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $siteId = $request->input('site_id');

        $base = JournalTresorerie::forOrg($orgId)
            ->byPeriode(Carbon::parse($dateFrom), Carbon::parse($dateTo));

        if (! $user->isAdmin()) {
            $siteIds = $user->sites()->pluck('sites.id')->all();
            $base->whereIn('site_id', $siteIds);
        } elseif ($siteId) {
            $base->where('site_id', $siteId);
        }

        $entrees = (float) (clone $base)->where('sens', SensJournal::ENTREE->value)->sum('montant');
        $sorties = (float) (clone $base)->where('sens', SensJournal::SORTIE->value)->sum('montant');

        $journalRecent = (clone $base)
            ->with('site')
            ->orderByDesc('date_operation')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (JournalTresorerie $j) => [
                'id' => $j->id,
                'date_operation' => $j->date_operation?->toDateString(),
                'libelle' => $j->libelle,
                'sens' => $j->sens?->value,
                'categorie' => $j->categorie?->value,
                'categorie_label' => $j->categorie?->label(),
                'montant' => (float) $j->montant,
                'site' => $j->site ? ['id' => $j->site->id, 'nom' => $j->site->nom] : null,
            ]);

        $fichesAPayer = PaiementFiche::where('organization_id', $orgId)
            ->where('statut', '!=', \App\Enums\StatutFichePaiement::PAYE->value)
            ->count();

        $sites = $user->isAdmin()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            : collect();

        return Inertia::render('Comptabilite/Dashboard', [
            'stats_entrees' => $entrees,
            'stats_sorties' => $sorties,
            'solde' => $entrees - $sorties,
            'fiches_a_payer' => $fichesAPayer,
            'journal_recent' => $journalRecent,
            'sites' => $sites,
            'is_admin' => $user->isAdmin(),
            'filtres' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'site_id' => $siteId,
            ],
        ]);
    }
}
