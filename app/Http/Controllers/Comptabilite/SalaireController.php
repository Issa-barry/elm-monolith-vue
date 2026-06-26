<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\PaieCalculService;
use App\Services\SiteScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalaireController extends Controller
{
    public function __construct(private PaieCalculService $paieCalc, private SiteScopeService $siteScope) {}

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $filtreMois = (int) $request->input('mois', now()->month);
        $filtreAnnee = (int) $request->input('annee', now()->year);
        $filtreStatut = (string) $request->input('statut', '');
        $search = trim((string) $request->input('search', ''));

        $isAdmin = $user->isAdmin();
        $sites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);
        $siteIds = ! $isAdmin ? $this->siteScope->accessibleSiteIds($user)->all() : [];
        $filtreSiteIds = $isAdmin ? array_values(array_filter((array) $request->input('site_ids', []))) : [];

        $periode = PaiePeriode::firstOrCreate(
            ['organization_id' => $orgId, 'mois' => $filtreMois, 'annee' => $filtreAnnee],
            ['statut' => 'brouillon'],
        );

        if ($periode->lignes()->count() === 0) {
            $this->paieCalc->genererLignes($periode);
            $this->paieCalc->calculerPeriode($periode);
            $periode->refresh();
        }

        $lignes = collect();

        if ($periode) {
            $query = PaieLigne::with(['employe.site', 'contrat'])
                ->where('paie_periode_id', $periode->id);

            if ($filtreStatut !== '') {
                $query->where('statut', $filtreStatut);
            }

            if ($isAdmin && ! empty($filtreSiteIds)) {
                $query->whereHas('employe', fn ($q) => $q->whereIn('site_id', $filtreSiteIds));
            }

            if (! $isAdmin && ! empty($siteIds)) {
                $query->whereHas('employe', fn ($q) => $q->whereIn('site_id', $siteIds));
            }

            $allLignes = $query->get()->sortBy(fn ($l) => $l->employe?->nom_complet ?? '');

            if ($search !== '') {
                $s = mb_strtolower($search);
                $allLignes = $allLignes->filter(
                    fn ($l) => str_contains(mb_strtolower($l->employe?->nom_complet ?? ''), $s)
                );
            }

            $lignes = $allLignes->map(fn ($l) => [
                'id' => $l->id,
                'employe_id' => $l->employe_id,
                'employe_nom' => $l->employe?->nom_complet ?? '—',
                'poste' => $l->contrat?->type_contrat?->label() ?? '—',
                'site' => $l->employe?->site?->nom ?? '—',
                'salaire_base' => (float) $l->salaire_base,
                'brut' => (float) $l->brut,
                'total_primes' => (float) $l->total_primes,
                'total_avances' => (float) $l->total_avances,
                'total_retenues' => (float) $l->total_retenues,
                'deductions' => (float) $l->deductions,
                'net' => (float) $l->net,
                'deja_paye' => (float) $l->deja_paye,
                'reste_a_payer' => (float) $l->reste_a_payer,
                'statut' => $l->statut?->value ?? 'en_attente',
                'statut_label' => $l->statut?->label() ?? 'En attente',
            ])->values();
        }

        $kpis = [
            'nb_salaries' => $lignes->count(),
            'total_brut' => (float) $lignes->sum('brut'),
            'total_net' => (float) $lignes->sum('net'),
            'total_paye' => (float) $lignes->sum('deja_paye'),
            'total_reste' => (float) $lignes->sum('reste_a_payer'),
        ];

        $periodesDisponibles = PaiePeriode::where('organization_id', $orgId)
            ->orderByDesc('annee')
            ->orderByDesc('mois')
            ->get(['id', 'mois', 'annee'])
            ->map(fn ($p) => [
                'mois' => $p->mois,
                'annee' => $p->annee,
                'label' => $p->labelPeriode(),
            ])
            ->values();

        return Inertia::render('Comptabilite/Salaire/Index', [
            'lignes' => $lignes,
            'kpis' => $kpis,
            'periode' => $periode ? [
                'id' => $periode->id,
                'mois' => $periode->mois,
                'annee' => $periode->annee,
                'label' => $periode->labelPeriode(),
                'statut' => $periode->statut?->value,
            ] : null,
            'periodes_disponibles' => $periodesDisponibles,
            'filtre_mois' => $filtreMois,
            'filtre_annee' => $filtreAnnee,
            'filtre_statut' => $filtreStatut,
            'filtre_site_ids' => $filtreSiteIds,
            'search' => $search,
            'sites' => $sites,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function payerLigne(Request $request, string $ligneId): RedirectResponse
    {
        abort_unless(auth()->user()->can('comptabilite.payer'), 403);

        $orgId = auth()->user()->organization_id;

        $ligne = PaieLigne::whereHas('periode', fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($ligneId);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date'],
            'mode_paiement' => ['required', 'string', 'in:especes,virement,cheque,mobile_money'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $maxPaiement = (float) $ligne->reste_a_payer;
        if ((float) $data['montant'] > $maxPaiement + 0.01) {
            return back()->withErrors(['montant' => "Le montant dépasse le reste à payer ({$maxPaiement} GNF)."]);
        }

        $ligne->paiements()->create($data);
        $this->paieCalc->recalculerApresPaiement($ligne);

        $employe = $ligne->employe;
        $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
        $employeNom = $employe?->nom_complet ?? '—';
        app(AuditLogService::class)->record($ligne, AuditEvent::PAID, auth()->user(), null, null, [
            'module' => 'salaires',
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'description' => "Paiement de {$montantFmt} GNF effectué pour {$employeNom}",
        ], $orgId);

        return back()->with('success', 'Paiement enregistré.');
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $filtreMois = (int) $request->input('mois', now()->month);
        $filtreAnnee = (int) $request->input('annee', now()->year);

        $periode = PaiePeriode::where('organization_id', $orgId)
            ->where('mois', $filtreMois)
            ->where('annee', $filtreAnnee)
            ->first();

        $lignes = $periode
            ? PaieLigne::with(['employe.site', 'contrat'])
                ->where('paie_periode_id', $periode->id)
                ->get()
            : collect();

        $periodeLabel = $periode?->labelPeriode() ?? 'Aucune période';
        $filename = 'salaires-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($lignes, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Période', 'Salarié', 'Poste', 'Agence', 'Salaire base (GNF)', 'Brut (GNF)', 'Primes (GNF)', 'Déductions (GNF)', 'Net (GNF)', 'Déjà payé (GNF)', 'Reste (GNF)', 'Statut', 'Signature'], ';');
            foreach ($lignes as $l) {
                fputcsv($handle, [
                    $periodeLabel,
                    $l->employe?->nom_complet ?? '—',
                    $l->contrat?->type_contrat?->label() ?? '—',
                    $l->employe?->site?->nom ?? '—',
                    number_format((float) $l->salaire_base, 0, ',', ' '),
                    number_format((float) $l->brut, 0, ',', ' '),
                    number_format((float) $l->total_primes, 0, ',', ' '),
                    number_format((float) $l->deductions, 0, ',', ' '),
                    number_format((float) $l->net, 0, ',', ' '),
                    number_format((float) $l->deja_paye, 0, ',', ' '),
                    number_format((float) $l->reste_a_payer, 0, ',', ' '),
                    $l->statut?->label() ?? '—',
                    '',
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $filtreMois = (int) $request->input('mois', now()->month);
        $filtreAnnee = (int) $request->input('annee', now()->year);

        $periode = PaiePeriode::where('organization_id', $orgId)
            ->where('mois', $filtreMois)
            ->where('annee', $filtreAnnee)
            ->first();

        $lignes = $periode
            ? PaieLigne::with(['employe.site', 'contrat'])
                ->where('paie_periode_id', $periode->id)
                ->get()
                ->sortBy(fn ($l) => $l->employe?->nom_complet ?? '')
                ->map(fn ($l) => [
                    'employe_nom' => $l->employe?->nom_complet ?? '—',
                    'poste' => $l->contrat?->type_contrat?->label() ?? '—',
                    'site' => $l->employe?->site?->nom ?? '—',
                    'brut' => (float) $l->brut,
                    'total_primes' => (float) $l->total_primes,
                    'deductions' => (float) $l->deductions,
                    'net' => (float) $l->net,
                    'deja_paye' => (float) $l->deja_paye,
                    'reste_a_payer' => (float) $l->reste_a_payer,
                    'statut_label' => $l->statut?->label() ?? '—',
                ])
                ->values()
            : collect();

        $org = Organization::find($orgId);

        $pdf = Pdf::loadView('pdf.salaires', [
            'lignes' => $lignes,
            'periode_label' => $periode?->labelPeriode() ?? 'Aucune période',
            'org_nom' => $org?->nom ?? '',
            'total_brut' => $lignes->sum('brut'),
            'total_net' => $lignes->sum('net'),
            'total_paye' => $lignes->sum('deja_paye'),
            'total_reste' => $lignes->sum('reste_a_payer'),
            'generated_at' => now()->format('d/m/Y à H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('salaires-'.$filtreMois.'-'.$filtreAnnee.'.pdf');
    }
}
