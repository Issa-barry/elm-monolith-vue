<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\PeriodeCalculatorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaiementPeriodeController extends Controller
{
    public function __construct(private PeriodeCalculatorService $calculator) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaiementPeriode::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['type', 'statut', 'date_debut', 'date_fin', 'search']);

        $query = PaiementPeriode::forOrg($orgId)->with('site');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }
        if (! empty($filters['date_debut'])) {
            $query->whereDate('date_debut', '>=', $filters['date_debut']);
        }
        if (! empty($filters['date_fin'])) {
            $query->whereDate('date_fin', '<=', $filters['date_fin']);
        }
        if (! empty($filters['search'])) {
            $s = mb_strtolower(trim($filters['search']));
            $query->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', ["%{$s}%"]));
        }

        $periodes = $query->orderByDesc('date_debut')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PaiementPeriode $p) => $this->transform($p));

        $kpis = PaiementPeriode::forOrg($orgId)
            ->selectRaw('statut, COUNT(*) as total')
            ->groupBy('statut')
            ->pluck('total', 'statut');

        return Inertia::render('Comptabilite/Periodes/Index', [
            'periodes' => $periodes,
            'types' => TypePeriodePaiement::options(),
            'statuts' => StatutPeriodePaiement::options(),
            'filters' => $filters,
            'kpis' => [
                'brouillon' => (int) ($kpis[StatutPeriodePaiement::BROUILLON->value] ?? 0),
                'calculee' => (int) ($kpis[StatutPeriodePaiement::CALCULEE->value] ?? 0),
                'validee' => (int) ($kpis[StatutPeriodePaiement::VALIDEE->value] ?? 0),
                'cloturee' => (int) ($kpis[StatutPeriodePaiement::CLOTUREE->value] ?? 0),
            ],
            'can_create' => auth()->user()->can('create', PaiementPeriode::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PaiementPeriode::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Comptabilite/Periodes/Create', [
            'types' => TypePeriodePaiement::options(),
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PaiementPeriode::class);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', TypePeriodePaiement::values())],
            'site_id' => ['nullable', 'exists:sites,id'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'observations' => ['nullable', 'string'],
        ]);

        $periode = PaiementPeriode::create([
            'organization_id' => $orgId,
            'reference' => $this->genererReference($orgId, $data['date_debut']),
            'type' => $data['type'],
            'site_id' => $data['site_id'] ?? null,
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'],
            'statut' => StatutPeriodePaiement::BROUILLON->value,
            'observations' => $data['observations'] ?? null,
            'created_by' => auth()->id(),
        ]);

        app(AuditLogService::class)->record($periode, AuditEvent::CREATED, auth()->user(), null, null, [
            'module' => 'periodes_paiement',
            'site_id' => $periode->site_id,
            'description' => "Période {$periode->reference} créée",
        ]);

        return redirect()
            ->route('comptabilite.periodes.show', $periode)
            ->with('success', 'Période créée avec succès.');
    }

    public function show(PaiementPeriode $periode): Response
    {
        $this->authorize('view', $periode);

        $periode->load('site', 'createur', 'validateur');

        $allFiches = $periode->fiches()->with('site')->get();

        $fiches = $allFiches->sortBy('beneficiaire_nom')->map(fn (PaiementFiche $f) => [
            'id' => $f->id,
            'reference' => $f->reference,
            'beneficiaire_nom' => $f->beneficiaire_nom,
            'beneficiaire_type' => $f->beneficiaire_type,
            'site' => $f->site ? ['id' => $f->site->id, 'nom' => $f->site->nom] : null,
            'montant_brut' => (float) $f->montant_brut,
            'total_deductions' => (float) $f->total_deductions,
            'montant_net' => (float) $f->montant_net,
            'montant_paye' => (float) $f->montant_paye,
            'statut' => $f->statut?->value,
            'statut_label' => $f->statut?->label(),
        ])->values();

        $repartitionAgences = $allFiches
            ->groupBy('site_id')
            ->map(function ($group) {
                $site = $group->first()->site;
                $net = (float) $group->sum('montant_net');
                $paye = (float) $group->sum('montant_paye');

                return [
                    'site_nom' => $site?->nom ?? 'Sans agence',
                    'nb_beneficiaires' => $group->count(),
                    'montant_brut' => (float) $group->sum('montant_brut'),
                    'total_deductions' => (float) $group->sum('total_deductions'),
                    'montant_net' => $net,
                    'montant_paye' => $paye,
                    'reste' => max(0.0, $net - $paye),
                ];
            })
            ->sortByDesc('montant_net')
            ->values();

        return Inertia::render('Comptabilite/Periodes/Show', [
            'periode' => $this->transform($periode),
            'fiches' => $fiches,
            'stats' => [
                'total_brut' => (float) $allFiches->sum('montant_brut'),
                'total_deductions' => (float) $allFiches->sum('total_deductions'),
                'total_net' => (float) $allFiches->sum('montant_net'),
                'total_paye' => (float) $allFiches->sum('montant_paye'),
                'nb_a_payer' => $allFiches->where('statut', StatutFichePaiement::A_PAYER->value)->count(),
                'nb_partiellement_paye' => $allFiches->where('statut', StatutFichePaiement::PARTIELLEMENT_PAYE->value)->count(),
                'nb_paye' => $allFiches->where('statut', StatutFichePaiement::PAYE->value)->count(),
            ],
            'repartition_agences' => $repartitionAgences,
            'can' => [
                'calculer' => auth()->user()->can('calculer', $periode),
                'valider' => auth()->user()->can('valider', $periode),
                'cloturer' => auth()->user()->can('cloturer', $periode),
                'delete' => auth()->user()->can('delete', $periode),
            ],
        ]);
    }

    public function calculer(PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('calculer', $periode);

        $result = $this->calculator->calculer($periode);

        app(AuditLogService::class)->record($periode, AuditEvent::AUTO_GENERATED, auth()->user(), null, null, [
            'module' => 'periodes_paiement',
            'site_id' => $periode->site_id,
            'nb_fiches' => $result['nb_fiches'],
            'description' => "Calcul de la période {$periode->reference} : {$result['nb_fiches']} fiche(s) générée(s)",
        ]);

        if ($result['nb_fiches'] === 0) {
            $periode->loadMissing('site');
            $debut = $periode->date_debut?->format('d/m/Y') ?? '—';
            $fin = $periode->date_fin?->format('d/m/Y') ?? '—';
            $agence = $periode->site ? " pour l'agence {$periode->site->nom}" : '';
            $type = $periode->type?->label() ?? '';

            return back()->with('warning', "0 fiche générée : aucune commission {$type} trouvée entre le {$debut} et le {$fin}{$agence}.");
        }

        $n = $result['nb_fiches'];

        return back()->with('success', "{$n} fiche".($n > 1 ? 's' : '')." générée".($n > 1 ? 's' : '')." avec succès.");
    }

    public function valider(PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('valider', $periode);

        $periode->update([
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'validated_by' => auth()->id(),
            'validated_at' => now(),
        ]);

        app(AuditLogService::class)->record($periode, AuditEvent::VALIDATED, auth()->user(), null, null, [
            'module' => 'periodes_paiement',
            'site_id' => $periode->site_id,
            'description' => "Période {$periode->reference} validée",
        ]);

        return back()->with('success', 'Période validée.');
    }

    public function cloturer(PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('cloturer', $periode);

        $periode->update(['statut' => StatutPeriodePaiement::CLOTUREE->value]);

        app(AuditLogService::class)->record($periode, AuditEvent::STATUS_CHANGED, auth()->user(), null, null, [
            'module' => 'periodes_paiement',
            'site_id' => $periode->site_id,
            'statut_avant' => StatutPeriodePaiement::VALIDEE->value,
            'statut_apres' => StatutPeriodePaiement::CLOTUREE->value,
            'description' => "Période {$periode->reference} clôturée",
        ]);

        return back()->with('success', 'Période clôturée.');
    }

    public function exportPdf(PaiementPeriode $periode)
    {
        $this->authorize('view', $periode);

        $periode->load(['site', 'createur', 'fiches.site', 'fiches.lignes']);
        $org = Organization::find($periode->organization_id);
        $fiches = $periode->fiches->sortBy('beneficiaire_nom');

        $stats = [
            'total_brut' => (float) $fiches->sum('montant_brut'),
            'total_deductions' => (float) $fiches->sum('total_deductions'),
            'total_net' => (float) $fiches->sum('montant_net'),
            'total_paye' => (float) $fiches->sum('montant_paye'),
            'reste' => (float) $fiches->sum('montant_net') - (float) $fiches->sum('montant_paye'),
            'nb_beneficiaires' => $fiches->count(),
        ];

        $repartitionAgences = $fiches
            ->groupBy('site_id')
            ->map(function ($group) {
                $site = $group->first()->site;
                $net = (float) $group->sum('montant_net');
                $paye = (float) $group->sum('montant_paye');

                return [
                    'site_nom' => $site?->nom ?? 'Sans agence',
                    'nb_beneficiaires' => $group->count(),
                    'montant_brut' => (float) $group->sum('montant_brut'),
                    'total_deductions' => (float) $group->sum('total_deductions'),
                    'montant_net' => $net,
                    'montant_paye' => $paye,
                    'reste' => max(0.0, $net - $paye),
                ];
            })
            ->sortByDesc('montant_net')
            ->values();

        $pdf = Pdf::loadView('pdf.periode_paiement', [
            'periode' => $periode,
            'fiches' => $fiches,
            'org' => $org,
            'stats' => $stats,
            'repartition_agences' => $repartitionAgences,
            'generated_at' => now(),
            'printed_by' => auth()->user()->name,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('periode-'.$periode->reference.'.pdf');
    }

    public function destroy(PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('delete', $periode);

        app(AuditLogService::class)->record($periode, AuditEvent::DELETED, auth()->user(), null, null, [
            'module' => 'periodes_paiement',
            'site_id' => $periode->site_id,
            'description' => "Période {$periode->reference} supprimée",
        ]);

        $periode->delete();

        return redirect()
            ->route('comptabilite.periodes.index')
            ->with('success', 'Période supprimée.');
    }

    private function transform(PaiementPeriode $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'type' => $p->type?->value,
            'type_label' => $p->type?->label(),
            'site' => $p->site ? ['id' => $p->site->id, 'nom' => $p->site->nom] : null,
            'date_debut' => $p->date_debut?->toDateString(),
            'date_fin' => $p->date_fin?->toDateString(),
            'statut' => $p->statut?->value,
            'statut_label' => $p->statut?->label(),
            'observations' => $p->observations,
            'nb_fiches' => $p->nb_fiches,
            'total_net' => $p->total_net,
            'total_paye' => $p->total_paye,
        ];
    }

    private function genererReference(string $orgId, string $dateDebut): string
    {
        $prefix = 'PAY-'.Carbon::parse($dateDebut)->format('Ym').'-';
        $count = PaiementPeriode::forOrg($orgId)
            ->where('reference', 'like', $prefix.'%')
            ->count();

        return $prefix.str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
}
