<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Services\CommissionPaymentService;
use App\Services\CommissionSearchService;
use App\Services\PeriodeComptableService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionLogistiqueController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    private const MODES_PAIEMENT = ['especes', 'virement', 'cheque', 'mobile_money'];

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $search = trim((string) $request->input('search', ''));
        $filtreStatut = (string) $request->input('statut', '');
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreSite = trim((string) $request->input('site', ''));

        $rows = CommissionPaymentService::soldesParLivreur(
            $orgId,
            $filtrePeriode !== '' ? $filtrePeriode : null,
            $filtreSite !== '' ? $filtreSite : null
        );

        $periodesDisponibles = CommissionLogistiquePart::query()
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->whereNotNull('periode')
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->select('periode')
            ->distinct()
            ->orderByDesc('periode')
            ->pluck('periode')
            ->map(fn (string $code) => [
                'code' => $code,
                'label' => PeriodeComptableService::labelForCode($code),
            ])
            ->values();

        $allLivreurIds = $rows->pluck('livreur_id')->filter()->unique()->values()->toArray();
        $telephones = Livreur::whereIn('id', $allLivreurIds)->pluck('telephone', 'id');

        $fraisDepensesParLivreur = [];
        if (! empty($allLivreurIds)) {
            $depQuery = Depense::where('beneficiaire_type', 'livreur')
                ->whereIn('beneficiaire_id', $allLivreurIds)
                ->where('statut', StatutDepense::VALIDE->value)
                ->where('organization_id', $orgId);
            if ($filtrePeriode !== '') {
                [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                $depQuery->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString()]);
            }
            $fraisDepensesParLivreur = $depQuery->get(['beneficiaire_id', 'montant'])
                ->groupBy('beneficiaire_id')
                ->map(fn ($d) => (float) $d->sum('montant'))
                ->toArray();
        }

        $partsParLivreur = CommissionLogistiquePart::with([
            'commission.vehicule:id,nom_vehicule,immatriculation',
            'commission.transfert.siteSource:id,nom',
        ])
            ->whereIn('livreur_id', $allLivreurIds)
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->when($filtrePeriode !== '', fn ($q) => $q->where('periode', $filtrePeriode))
            ->when($filtreSite !== '', fn ($q) => $q->whereHas(
                'commission.transfert',
                fn ($t) => $t->where('site_source_id', $filtreSite)->orWhere('site_destination_id', $filtreSite)
            ))
            ->get()
            ->groupBy('livreur_id');

        $vehiculesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('commission.vehicule')
            ->filter()->unique('id')
            ->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))
            ->values()->implode(' ')
        );

        $agencesParLivreur = $partsParLivreur->map(fn ($parts) => $parts
            ->pluck('commission.transfert.siteSource.nom')
            ->filter()->unique()->sort()->implode(', ')
        );

        $livreurs = $rows->map(function ($row) use ($telephones, $vehiculesParLivreur, $agencesParLivreur, $fraisDepensesParLivreur) {
            $frais = $fraisDepensesParLivreur[(string) $row->livreur_id] ?? 0.0;
            $impaye = max(0.0, (float) $row->impaye - $frais);

            return [
                'livreur_id' => $row->livreur_id,
                'nom' => $row->beneficiaire_nom,
                'telephone' => $telephones[$row->livreur_id] ?? null,
                'vehicules' => $vehiculesParLivreur[$row->livreur_id] ?? null,
                'agence' => $agencesParLivreur[$row->livreur_id] ?? null,
                'frais_depenses' => $frais,
                'impaye' => $impaye,
                'paye' => (float) $row->paye,
            ];
        });

        if ($filtreStatut !== '') {
            $livreurs = match ($filtreStatut) {
                'impaye' => $livreurs->filter(fn ($r) => $r['impaye'] > 0),
                'paye' => $livreurs->filter(fn ($r) => $r['paye'] > 0 && $r['impaye'] <= 0),
                default => $livreurs,
            };
        }

        $livreurs = CommissionSearchService::filter($livreurs, $search)->values();

        $kpis = [
            'nb_livreurs' => $livreurs->count(),
            'total_impaye' => (float) $livreurs->sum('impaye'),
            'total_paye' => (float) $livreurs->sum('paye'),
        ];

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => $s->nom])
            ->values();

        return Inertia::render('Comptabilite/CommissionLogistique/Index', [
            'livreurs' => $livreurs,
            'kpis' => $kpis,
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_site' => $filtreSite,
            'selected_periode' => $filtrePeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'sites' => $sites,
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function showLivreur(Request $request, string $livreurId): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $allParts = CommissionPaymentService::releveLivreur($livreurId, $orgId);

        $livreurNom = $allParts->first()?->beneficiaire_nom ?? '—';
        $livreurTelephone = Livreur::find($livreurId)?->telephone;

        $totalImpaye = (float) $allParts
            ->filter(fn ($p) => in_array($p->statut, [StatutCommission::IMPAYE, StatutCommission::PARTIEL], true))
            ->sum('montant_restant');

        $totalPaye = (float) $allParts
            ->filter(fn ($p) => $p->statut === StatutCommission::PAYE)
            ->sum('montant_net');

        $earliestPart = $allParts->whereNotNull('periode')->sortBy('earned_at')->first();
        $earliestDate = $earliestPart?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles($earliestDate);

        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $selectedPeriode = $request->input('periode', '');

        $filteredParts = $selectedPeriode !== ''
            ? $allParts->filter(fn ($p) => $p->periode === $selectedPeriode)
            : $allParts;

        $periodeStats = null;

        if ($selectedPeriode !== '' && $filteredParts->isNotEmpty()) {
            $totalCommissionPeriode = (float) $filteredParts->sum('montant_net');
            $totalVersePeriode = (float) $filteredParts
                ->flatMap(fn ($p) => $p->paymentItems)
                ->sum('amount_allocated');
            $restePeriode = max(0.0, $totalCommissionPeriode - $totalVersePeriode);

            [$statutVal, $statutLabel, $statutDot] = match (true) {
                $totalVersePeriode <= 0 => [StatutCommission::IMPAYE->value, 'Impayé', StatutCommission::IMPAYE->dotClass()],
                $restePeriode < 0.01 => [StatutCommission::PAYE->value, 'Payé', StatutCommission::PAYE->dotClass()],
                default => [StatutCommission::PARTIEL->value, 'Partiel', StatutCommission::PARTIEL->dotClass()],
            };

            $periodeStats = [
                'code' => $selectedPeriode,
                'label' => PeriodeComptableService::labelForCode($selectedPeriode),
                'total_commission' => $totalCommissionPeriode,
                'total_verse' => $totalVersePeriode,
                'reste' => $restePeriode,
                'statut' => $statutVal,
                'statut_label' => $statutLabel,
                'statut_dot_class' => $statutDot,
            ];
        }

        $filteredPartIds = $filteredParts->pluck('id')->toArray();

        $paymentsQuery = CommissionPayment::with('createur:id,prenom,nom')
            ->where('organization_id', $orgId)
            ->where('livreur_id', $livreurId)
            ->where('beneficiary_type', 'livreur')
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($selectedPeriode !== '') {
            count($filteredPartIds) > 0
                ? $paymentsQuery->whereHas('items', fn ($q) => $q->whereIn('part_id', $filteredPartIds))
                : $paymentsQuery->whereRaw('1 = 0');
        }

        $payments = $paymentsQuery->get()->map(fn ($p) => [
            'id' => $p->id,
            'montant' => (float) $p->montant,
            'mode_paiement' => $p->mode_paiement,
            'note' => $p->note,
            'paid_at' => $p->paid_at?->format(self::DATE_FORMAT),
            'created_by' => $p->createur ? trim("{$p->createur->prenom} {$p->createur->nom}") : null,
        ]);

        return Inertia::render('Comptabilite/CommissionLogistique/Livreur/Show', [
            'livreur' => [
                'id' => $livreurId,
                'nom' => $livreurNom,
                'telephone' => $livreurTelephone,
            ],
            'kpis' => [
                'impaye' => $totalImpaye,
                'paye' => $totalPaye,
            ],
            'parts' => $filteredParts->map(fn ($p) => [
                'id' => $p->id,
                'transfert_reference' => $p->commission?->transfert?->reference,
                'montant_net' => (float) $p->montant_net,
                'earned_at' => $p->earned_at?->format(self::DATE_FORMAT),
                'periode' => $p->periode,
                'periode_label' => $p->periode ? PeriodeComptableService::labelForCode($p->periode) : null,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_dot_class' => $p->statut_dot_class,
            ])->values(),
            'periode_stats' => $periodeStats,
            'payments' => $payments,
            'periode_courante' => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode' => $selectedPeriode,
            'periodes_disponibles' => $periodesDisponibles,
            'modes_paiement' => [
                ['value' => 'especes', 'label' => 'Espèces'],
                ['value' => 'virement', 'label' => 'Virement'],
                ['value' => 'cheque', 'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('comptabilite.payer'),
        ]);
    }

    public function payerLivreur(Request $request, string $livreurId): RedirectResponse
    {
        abort_unless(auth()->user()->can('comptabilite.payer'), 403);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:1'],
            'mode_paiement' => ['required', Rule::in(self::MODES_PAIEMENT)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            CommissionPaymentService::payerLivreur(
                livreurId: $livreurId,
                orgId: auth()->user()->organization_id,
                montant: (float) $data['montant'],
                modePaiement: $data['mode_paiement'],
                paidAt: now()->toDateString(),
                note: $data['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        }

        return back()->with('success', 'Paiement enregistré.');
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreSite = trim((string) $request->input('site', ''));
        $filtreStatut = trim((string) $request->input('statut', ''));
        $search = trim((string) $request->input('search', ''));

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSite);
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search);

        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';
        $filename = 'commissions-logistique-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Période', 'Total cumulé (GNF)', 'Frais (GNF)', 'Motif de frais', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut', 'Signature'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['telephone'] ?? '',
                    $row['vehicules'] ?? '',
                    $row['agence'] ?? '',
                    $periodeLabel,
                    number_format((float) $row['total_cumule'], 0, ',', ' '),
                    number_format((float) $row['frais'], 0, ',', ' '),
                    $row['motifs_frais'] ?? '',
                    number_format((float) $row['deja_paye'], 0, ',', ' '),
                    number_format((float) $row['reste'], 0, ',', ' '),
                    $row['statut'],
                    '',
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request): HttpResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $orgId = auth()->user()->organization_id;
        $filtrePeriode = trim((string) $request->input('periode', ''));
        $filtreSite = trim((string) $request->input('site', ''));
        $filtreStatut = trim((string) $request->input('statut', ''));
        $search = trim((string) $request->input('search', ''));

        $parts = $this->loadPartsForExport($orgId, $filtrePeriode, $filtreSite);
        $rows = $this->buildExportRows($parts, $filtrePeriode, $filtreStatut, $search);
        $siteGroups = $this->buildSiteGroups($rows);

        $org = Organization::find($orgId);
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.index', [
            'title' => 'Commissions livreur logistique',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'filters' => ['statut' => $filtreStatut, 'search' => $search],
            'sites' => $siteGroups,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-logistique-'.now()->format('Y-m-d').'.pdf');
    }

    private function loadPartsForExport(string $orgId, string $filtrePeriode, string $filtreSite): Collection
    {
        return CommissionLogistiquePart::with([
            'commission.transfert.siteSource:id,nom',
            'commission.vehicule:id,nom_vehicule,immatriculation',
            'livreur:id,telephone',
        ])
            ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
            ->where('type_beneficiaire', 'livreur')
            ->whereNotNull('livreur_id')
            ->when($filtrePeriode !== '', fn ($q) => $q->where('periode', $filtrePeriode))
            ->when($filtreSite !== '', fn ($q) => $q->whereHas(
                'commission.transfert',
                fn ($t) => $t->where('site_source_id', $filtreSite)
            ))
            ->get();
    }

    private function buildExportRows(Collection $parts, string $filtrePeriode, string $filtreStatut, string $search): Collection
    {
        $rows = $parts->groupBy('livreur_id')->map(function (Collection $livParts) use ($filtrePeriode) {
            $first = $livParts->first();
            $totalBrut = (float) $livParts->sum('montant_brut');
            $totalFrais = (float) $livParts->sum('frais_supplementaires');
            $totalNet = (float) $livParts->sum('montant_net');
            $totalVerse = (float) $livParts->sum('montant_verse');
            $solde = max(0.0, $totalNet - $totalVerse);

            $vehicules = $livParts->pluck('commission.vehicule')
                ->filter()->unique('id')
                ->map(fn ($v) => $v->nom_vehicule.($v->immatriculation ? ' '.$v->immatriculation : ''))
                ->implode(', ');

            $agence = $livParts->pluck('commission.transfert.siteSource.nom')
                ->filter()->unique()->sort()->implode(', ');

            $motifs = $livParts->pluck('type_frais')
                ->filter()->unique()
                ->map(fn ($t) => self::labelTypeFrais($t))
                ->implode(', ');

            $periodeLabel = $filtrePeriode !== ''
                ? PeriodeComptableService::labelForCode($filtrePeriode)
                : $livParts->pluck('periode')->filter()->unique()
                    ->map(fn ($c) => PeriodeComptableService::labelForCode($c))
                    ->implode(', ');

            $statut = match (true) {
                $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE->label(),
                $totalVerse > 0 => StatutCommission::PARTIEL->label(),
                default => StatutCommission::IMPAYE->label(),
            };

            return [
                'beneficiaire_id' => $first->livreur_id,
                'beneficiaire_nom' => $first->beneficiaire_nom ?? '—',
                'telephone' => $first->livreur?->telephone,
                'vehicules' => $vehicules ?: null,
                'agence' => $agence ?: null,
                'periode' => $periodeLabel,
                'total_cumule' => $totalNet,
                'frais' => $totalFrais,
                'motifs_frais' => $motifs ?: null,
                'deja_paye' => $totalVerse,
                'reste' => $solde,
                'statut' => $statut,
            ];
        });

        if ($filtreStatut !== '') {
            $statutLabel = match ($filtreStatut) {
                'impaye' => StatutCommission::IMPAYE->label(),
                'paye' => StatutCommission::PAYE->label(),
                'partiel' => StatutCommission::PARTIEL->label(),
                default => null,
            };
            if ($statutLabel !== null) {
                $rows = $rows->filter(fn ($r) => $r['statut'] === $statutLabel);
            }
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['beneficiaire_nom']), $s));
        }

        return $rows->sortBy('beneficiaire_nom')->values();
    }

    private function buildSiteGroups(Collection $rows): array
    {
        $grouped = $rows->groupBy(fn ($r) => $r['agence'] ?? 'Sans agence')
            ->sortKeys()
            ->map(function (Collection $siteRows, string $siteNom) {
                return [
                    'site_nom' => $siteNom === 'Sans agence' ? null : $siteNom,
                    'rows' => $siteRows->values()->toArray(),
                    'totaux' => [
                        'total_cumule' => (float) $siteRows->sum('total_cumule'),
                        'total_frais' => (float) $siteRows->sum('frais'),
                        'total_deja_paye' => (float) $siteRows->sum('deja_paye'),
                        'total_reste' => (float) $siteRows->sum('reste'),
                    ],
                ];
            });

        return $grouped->isEmpty()
            ? [['site_nom' => null, 'rows' => [], 'totaux' => ['total_cumule' => 0, 'total_frais' => 0, 'total_deja_paye' => 0, 'total_reste' => 0]]]
            : $grouped->values()->toArray();
    }

    private static function labelTypeFrais(?string $type): string
    {
        return match ($type) {
            'carburant' => 'Carburant',
            'reparation' => 'Réparation',
            'autre' => 'Autre',
            default => (string) $type,
        };
    }
}
