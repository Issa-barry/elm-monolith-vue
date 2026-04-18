<?php

namespace App\Http\Controllers;

use App\Enums\StatutPartCommission;
use App\Models\CommissionPayment;
use App\Models\Livreur;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use App\Services\PeriodeComptableService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVehiculeController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    // ── Index : liste des livreurs avec commissions cumulées ─────────────────

    /**
     * GET /logistique/commissions
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;
        $search = (string) $request->input('search', '');
        $filtreStatut = (string) $request->input('statut', '');

        $rows = CommissionPaymentService::soldesParLivreur($orgId);

        if ($search !== '') {
            $q = mb_strtolower($search);
            $rows = $rows->filter(
                fn ($r) => str_contains(mb_strtolower((string) $r->beneficiaire_nom), $q)
            );
        }

        if ($filtreStatut !== '') {
            $rows = match ($filtreStatut) {
                'pending' => $rows->filter(fn ($r) => $r->pending > 0 && $r->available <= 0),
                'available' => $rows->filter(fn ($r) => $r->available > 0),
                'paid' => $rows->filter(fn ($r) => $r->paid > 0),
                default => $rows,
            };
        }

        $list = $rows->values();

        $kpis = [
            'nb_livreurs' => $list->count(),
            'total_pending' => (float) $list->sum('pending'),
            'total_available' => (float) $list->sum('available'),
            'total_paid' => (float) $list->sum('paid'),
        ];

        $livreurIds = $list->pluck('livreur_id')->filter()->unique()->values()->toArray();
        $telephones = Livreur::whereIn('id', $livreurIds)->pluck('telephone', 'id');

        $livreurs = $list->map(fn ($row) => [
            'livreur_id' => (int) $row->livreur_id,
            'nom' => $row->beneficiaire_nom,
            'telephone' => $telephones[(int) $row->livreur_id] ?? null,
            'pending' => (float) $row->pending,
            'available' => (float) $row->available,
            'paid' => (float) $row->paid,
        ])->values();

        return Inertia::render('Logistique/Commissions/Index', [
            'livreurs' => $livreurs,
            'kpis' => $kpis,
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'can_payer' => auth()->user()->can('logistique.commission.verser'),
        ]);
    }

    // ── Show livreur : cumul global + relevé par transfert ───────────────────

    /**
     * GET /logistique/commissions/livreurs/{livreurId}
     */
    public function showLivreur(Request $request, int $livreurId): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        $orgId = auth()->user()->organization_id;

        // ── Toutes les parts du livreur (tri FIFO earned_at) ─────────────────
        $allParts = CommissionPaymentService::releveLivreur($livreurId, $orgId);

        $livreurNom = $allParts->first()?->beneficiaire_nom ?? '—';
        $livreurTelephone = Livreur::find($livreurId)?->telephone;

        // ── KPIs globaux (toutes périodes confondues) ─────────────────────────
        $totalPending = (float) $allParts
            ->filter(fn ($p) => $p->statut === StatutPartCommission::PENDING)
            ->sum('montant_net');

        $totalAvailable = (float) $allParts
            ->filter(fn ($p) => in_array($p->statut, [
                StatutPartCommission::AVAILABLE,
                StatutPartCommission::PARTIAL,
            ], true))
            ->sum('montant_restant');

        $totalPaid = (float) $allParts
            ->filter(fn ($p) => $p->statut === StatutPartCommission::PAID)
            ->sum('montant_net');

        // ── Périodes disponibles (depuis la 1re commission jusqu'à aujourd'hui) ─
        $earliestPart = $allParts->whereNotNull('periode')->sortBy('earned_at')->first();
        $earliestDate = $earliestPart?->earned_at ?? now();
        $periodesDisponibles = PeriodeComptableService::periodesDisponibles($earliestDate);

        // Période sélectionnée (filtre URL ?periode=2026-04-P1)
        $periodeCourante = PeriodeComptableService::periodeCouranteLivreur();
        $selectedPeriode = $request->input('periode', '');

        // ── Parts filtrées pour l'affichage ───────────────────────────────────
        $filteredParts = $selectedPeriode !== ''
            ? $allParts->filter(fn ($p) => $p->periode === $selectedPeriode)
            : $allParts;

        // ── Statistiques de la période sélectionnée ───────────────────────────
        $periodeStats = null;

        if ($selectedPeriode !== '' && $filteredParts->isNotEmpty()) {
            $totalCommissionPeriode = (float) $filteredParts->sum('montant_net');

            // Total versé = somme des allocations sur les parts de cette période
            $totalVersePeriode = (float) $filteredParts
                ->flatMap(fn ($p) => $p->paymentItems)
                ->sum('amount_allocated');

            $restePeriode = max(0.0, $totalCommissionPeriode - $totalVersePeriode);

            $allPending = $filteredParts->every(fn ($p) => $p->statut === StatutPartCommission::PENDING);

            [$statutVal, $statutLabel, $statutDot] = match (true) {
                $allPending          => ['pending',        'En attente',             'bg-zinc-400 dark:bg-zinc-500'],
                $totalVersePeriode <= 0 => ['available',   'Non versée',             'bg-amber-500'],
                $restePeriode < 0.01 => ['paid',           'Soldée',                 'bg-emerald-500'],
                default              => ['partially_paid', 'Partiellement versée',   'bg-blue-500'],
            };

            $periodeStats = [
                'code'            => $selectedPeriode,
                'label'           => PeriodeComptableService::labelForCode($selectedPeriode),
                'total_commission' => $totalCommissionPeriode,
                'total_verse'     => $totalVersePeriode,
                'reste'           => $restePeriode,
                'statut'          => $statutVal,
                'statut_label'    => $statutLabel,
                'statut_dot_class' => $statutDot,
            ];
        }

        // ── Historique des paiements (filtré par période si applicable) ────────
        $filteredPartIds = $filteredParts->pluck('id')->toArray();

        $paymentsQuery = CommissionPayment::with('createur:id,prenom,nom')
            ->where('organization_id', $orgId)
            ->where('livreur_id', $livreurId)
            ->where('beneficiary_type', 'livreur')
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($selectedPeriode !== '') {
            // Uniquement les paiements ayant au moins une allocation sur cette période
            count($filteredPartIds) > 0
                ? $paymentsQuery->whereHas('items', fn ($q) => $q->whereIn('part_id', $filteredPartIds))
                : $paymentsQuery->whereRaw('1 = 0');
        }

        $payments = $paymentsQuery->get()->map(fn ($p) => [
            'id'           => $p->id,
            'montant'      => (float) $p->montant,
            'mode_paiement' => $p->mode_paiement,
            'note'         => $p->note,
            'paid_at'      => $p->paid_at?->format(self::DATE_FORMAT),
            'created_by'   => $p->createur
                ? trim("{$p->createur->prenom} {$p->createur->nom}")
                : null,
        ]);

        return Inertia::render('Logistique/Commissions/Livreur/Show', [
            'livreur' => [
                'id'        => $livreurId,
                'nom'       => $livreurNom,
                'telephone' => $livreurTelephone,
            ],
            'kpis' => [
                'pending'   => $totalPending,
                'available' => $totalAvailable,
                'paid'      => $totalPaid,
            ],
            'parts' => $filteredParts->map(fn ($p) => [
                'id'                  => $p->id,
                'transfert_reference' => $p->commission?->transfert?->reference,
                'montant_net'         => (float) $p->montant_net,
                'earned_at'           => $p->earned_at?->format(self::DATE_FORMAT),
                'periode'             => $p->periode,
                'periode_label'       => $p->periode ? PeriodeComptableService::labelForCode($p->periode) : null,
                'statut'              => $p->statut?->value,
                'statut_label'        => $p->statut_label,
                'statut_dot_class'    => $p->statut_dot_class,
            ])->values(),
            'periode_stats' => $periodeStats,
            'payments'      => $payments,
            'periode_courante'       => $periodeCourante,
            'periode_courante_label' => PeriodeComptableService::labelForCode($periodeCourante),
            'selected_periode'       => $selectedPeriode,
            'periodes_disponibles'   => $periodesDisponibles,
            'modes_paiement' => [
                ['value' => 'especes',      'label' => 'Espèces'],
                ['value' => 'virement',     'label' => 'Virement'],
                ['value' => 'cheque',       'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('logistique.commission.verser'),
        ]);
    }

    // ── Show : détail par véhicule ────────────────────────────────────────────

    /**
     * GET /logistique/commissions/vehicules/{vehicule}
     */
    public function show(Request $request, Vehicule $vehicule): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);
        abort_unless($vehicule->organization_id === auth()->user()->organization_id, 403);

        $soldes = CommissionPaymentService::soldesParVehicule($vehicule);
        $payments = \App\Models\CommissionPayment::with('createur:id,prenom,nom')
            ->where('vehicule_id', $vehicule->id)
            ->where('organization_id', $vehicule->organization_id)
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'beneficiary_type' => $p->beneficiary_type,
                'beneficiary_nom' => $p->beneficiary_nom,
                'montant' => (float) $p->montant,
                'mode_paiement' => $p->mode_paiement,
                'note' => $p->note,
                'paid_at' => $p->paid_at?->format(self::DATE_FORMAT),
                'created_by' => $p->createur ? trim("{$p->createur->prenom} {$p->createur->nom}") : null,
            ]);

        return Inertia::render('Logistique/Commissions/Vehicule/Show', [
            'vehicule' => [
                'id' => $vehicule->id,
                'nom' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
            ],
            'livreurs' => $soldes['livreurs'],
            'proprietaires' => $soldes['proprietaires'],
            'payments' => $payments,
            'modes_paiement' => [
                ['value' => 'especes',      'label' => 'Espèces'],
                ['value' => 'virement',     'label' => 'Virement'],
                ['value' => 'cheque',       'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_payer' => auth()->user()->can('verserCommission', $vehicule->commissions()->first()
                ? $vehicule->commissions()->first()->transfert
                : new \App\Models\TransfertLogistique),
        ]);
    }

    // ── Relevé détaillé d'un bénéficiaire ────────────────────────────────────

    /**
     * GET /logistique/commissions/vehicules/{vehicule}/beneficiaires/{type}/{id}
     */
    public function releve(Request $request, Vehicule $vehicule, string $type, int $beneficiaireId): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);
        abort_unless($vehicule->organization_id === auth()->user()->organization_id, 403);
        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422);

        $parts = CommissionPaymentService::releve($vehicule, $type, $beneficiaireId);

        $nom = $parts->first()?->beneficiaire_nom ?? '—';

        return Inertia::render('Logistique/Commissions/Beneficiaire/Show', [
            'vehicule' => [
                'id' => $vehicule->id,
                'nom' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
            ],
            'beneficiaire' => [
                'id' => $beneficiaireId,
                'type' => $type,
                'nom' => $nom,
            ],
            'parts' => $parts->map(fn ($p) => [
                'id' => $p->id,
                'transfert_reference' => $p->commission?->transfert?->reference,
                'taux_commission' => (float) $p->taux_commission,
                'montant_brut' => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net' => (float) $p->montant_net,
                'montant_verse' => (float) $p->montant_verse,
                'montant_restant' => (float) $p->montant_restant,
                'earned_at' => $p->earned_at?->format(self::DATE_FORMAT),
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_dot_class' => $p->statut_dot_class,
                'payments' => $p->paymentItems->map(fn ($item) => [
                    'paid_at' => $item->payment?->paid_at?->format(self::DATE_FORMAT),
                    'montant' => (float) $item->amount_allocated,
                    'mode_paiement' => $item->payment?->mode_paiement,
                ])->values()->all(),
            ])->values(),
        ]);
    }
}
