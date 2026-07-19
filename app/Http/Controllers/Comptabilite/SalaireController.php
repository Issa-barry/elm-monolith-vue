<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\PaiePeriode;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\PaieCalculService;
use App\Services\SiteScopeService;
use App\Support\Commission\CommissionSummaryFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalaireController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    private const MODES_PAIEMENT = [
        ['value' => 'especes', 'label' => 'Espèces'],
        ['value' => 'virement', 'label' => 'Virement'],
        ['value' => 'cheque', 'label' => 'Chèque'],
        ['value' => 'mobile_money', 'label' => 'Mobile Money'],
    ];

    private const STATUT_DOT_CLASS = [
        'paye' => 'bg-emerald-500',
        'partiellement_paye' => 'bg-amber-500',
        'calcule' => 'bg-blue-500',
        'en_attente' => 'bg-zinc-400 dark:bg-zinc-500',
    ];

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
        $orgId = auth()->user()->organization_id;

        $ligne = PaieLigne::with('periode')
            ->whereHas('periode', fn ($q) => $q->where('organization_id', $orgId))
            ->findOrFail($ligneId);

        $this->authorize('pay', $ligne->periode);

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

    public function showEmploye(Request $request, string $employeId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;

        $employe = Employe::where('organization_id', $orgId)->findOrFail($employeId);

        $lignes = PaieLigne::with('periode')
            ->where('employe_id', $employeId)
            ->whereHas('periode', fn ($q) => $q->where('organization_id', $orgId))
            ->get()
            ->sortByDesc(fn (PaieLigne $l) => sprintf('%04d-%02d', $l->periode->annee, $l->periode->mois));

        $totalBrut = (float) $lignes->sum('brut');
        $totalDeductions = (float) $lignes->sum('deductions');
        $totalNet = (float) $lignes->sum('net');
        $totalVerse = (float) $lignes->sum('deja_paye');
        $totalReste = (float) $lignes->sum('reste_a_payer');

        $commissionDetails = $lignes->map(fn (PaieLigne $l) => [
            'id' => $l->id,
            'reference' => $l->periode->labelPeriode(),
            'date' => null,
            'vehicule' => null,
            'montant' => (float) $l->net,
            'paye' => (float) $l->deja_paye,
            'reste' => (float) $l->reste_a_payer,
            'statut' => $l->statut?->label() ?? '—',
            'statut_dot_class' => self::STATUT_DOT_CLASS[$l->statut?->value] ?? 'bg-zinc-400 dark:bg-zinc-500',
        ])->values();

        $expenses = Depense::with(['user', 'validateur', 'depenseType:id,libelle'])
            ->where('organization_id', $orgId)
            ->where('beneficiaire_type', 'employe')
            ->where('beneficiaire_id', $employeId)
            ->where('statut', StatutDepense::VALIDE->value)
            ->orderByDesc('date_depense')
            ->get()
            ->map(fn (Depense $d) => [
                'id' => $d->id,
                'date' => $d->date_depense?->format(self::DATE_FORMAT),
                'type' => $d->depenseType?->libelle ?? '—',
                'commentaire' => $d->commentaire,
                'saisi_par' => $d->user?->name,
                'validateur' => $d->validateur?->name,
                'vehicule' => null,
                'montant' => (float) $d->montant,
            ]);

        $payments = PaiePaiement::whereIn('paie_ligne_id', $lignes->pluck('id'))
            ->orderByDesc('date_paiement')
            ->get()
            ->map(fn (PaiePaiement $p) => [
                'id' => $p->id,
                'paid_at' => $p->date_paiement?->format(self::DATE_FORMAT),
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement,
                'note' => $p->note,
                'created_by' => null,
            ]);

        return Inertia::render('Comptabilite/Salaire/Employe/Show', [
            'employe' => [
                'id' => $employeId,
                'nom' => $employe->nom_complet,
                'telephone' => $employe->telephone,
            ],
            'commission_summary' => CommissionSummaryFormatter::format(
                $totalBrut,
                $totalDeductions,
                $totalNet,
                $totalVerse,
                $totalReste,
            ),
            'commission_details' => $commissionDetails,
            'expenses' => $expenses,
            'payments' => $payments,
            'modes_paiement' => self::MODES_PAIEMENT,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function payerEmploye(Request $request, string $employeId): RedirectResponse
    {
        abort_unless(auth()->user()->can('comptabilite.payer'), 403);

        $orgId = auth()->user()->organization_id;
        $employe = Employe::where('organization_id', $orgId)->findOrFail($employeId);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', 'string', 'in:especes,virement,cheque,mobile_money'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->paieCalc->payerEmploye(
                employeId: $employeId,
                orgId: $orgId,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        $montantFmt = number_format((float) $data['montant'], 0, ',', "\u{202F}");
        app(AuditLogService::class)->record($employe, AuditEvent::PAID, auth()->user(), null, null, [
            'module' => 'salaires',
            'montant' => $data['montant'],
            'mode_paiement' => $data['mode_paiement'],
            'description' => "Paiement de {$montantFmt} GNF effectué pour {$employe->nom_complet}",
        ]);

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
