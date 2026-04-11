<?php

namespace App\Http\Controllers;

use App\Enums\StatutPartCommission;
use App\Models\CommissionLogistiquePart;
use App\Models\Vehicule;
use App\Services\CommissionPaymentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVehiculeController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    // ── Index : liste des véhicules ayant généré des commissions ─────────────

    /**
     * GET /logistique/commissions
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\TransfertLogistique::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin_entreprise']);

        // KPIs globaux + liste véhicules depuis les parts
        $vehiculesRaw = CommissionLogistiquePart::query()
            ->select([
                'vehicules.id          AS vehicule_id',
                'vehicules.nom_vehicule',
                'vehicules.immatriculation',
            ])
            ->selectRaw(
                'SUM(CASE WHEN clp.statut = ? THEN clp.montant_net ELSE 0 END)                                                                        AS pending,
                 SUM(CASE WHEN clp.statut IN (?,?) THEN CASE WHEN clp.montant_net > clp.montant_verse THEN clp.montant_net - clp.montant_verse ELSE 0 END ELSE 0 END) AS available,
                 SUM(CASE WHEN clp.statut = ? THEN clp.montant_net ELSE 0 END)                                                                        AS paid,
                 COUNT(DISTINCT cl.transfert_logistique_id)                                                                                            AS nb_transferts',
                [
                    StatutPartCommission::PENDING->value,
                    StatutPartCommission::AVAILABLE->value,
                    StatutPartCommission::PARTIAL->value,
                    StatutPartCommission::PAID->value,
                ]
            )
            ->from('commission_logistique_parts AS clp')
            ->join('commissions_logistiques AS cl', 'cl.id', '=', 'clp.commission_logistique_id')
            ->join('vehicules', 'vehicules.id', '=', 'cl.vehicule_id')
            ->where('cl.organization_id', $orgId)
            ->where('clp.statut', '!=', StatutPartCommission::CANCELLED->value)
            ->when(! $isAdmin, function ($q) use ($user) {
                $siteIds = $user->sites()->pluck('sites.id');
                $q->whereHas('commission.transfert', function ($sub) use ($siteIds) {
                    $sub->whereIn('site_source_id', $siteIds)
                        ->orWhereIn('site_destination_id', $siteIds);
                });
            })
            ->when($request->input('vehicule_id'), fn ($q, $v) => $q->where('cl.vehicule_id', $v))
            ->when($request->input('statut'), function ($q, $statut) {
                match ($statut) {
                    'pending' => $q->havingRaw('pending > 0'),
                    'available' => $q->havingRaw('available > 0'),
                    'paid' => $q->havingRaw('paid > 0'),
                    default => null,
                };
            })
            ->groupBy('vehicules.id', 'vehicules.nom_vehicule', 'vehicules.immatriculation')
            ->orderByRaw('available DESC, pending DESC')
            ->get();

        $kpis = [
            'nb_vehicules' => $vehiculesRaw->count(),
            'total_pending' => (float) $vehiculesRaw->sum('pending'),
            'total_available' => (float) $vehiculesRaw->sum('available'),
            'total_paid' => (float) $vehiculesRaw->sum('paid'),
        ];

        $vehicules = $vehiculesRaw->map(fn ($row) => [
            'vehicule_id' => $row->vehicule_id,
            'nom' => $row->nom_vehicule,
            'immatriculation' => $row->immatriculation,
            'pending' => (float) $row->pending,
            'available' => (float) $row->available,
            'paid' => (float) $row->paid,
            'nb_transferts' => (int) $row->nb_transferts,
        ])->values();

        // Liste véhicules pour le filtre dropdown
        $vehiculeOptions = Vehicule::where('organization_id', $orgId)
            ->select('id', 'nom_vehicule', 'immatriculation')
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn ($v) => [
                'value' => $v->id,
                'label' => $v->nom_vehicule.($v->immatriculation ? " ({$v->immatriculation})" : ''),
            ]);

        return Inertia::render('Logistique/Commissions/Index', [
            'vehicules' => $vehicules,
            'kpis' => $kpis,
            'vehicule_options' => $vehiculeOptions,
            'filtre_vehicule' => $request->input('vehicule_id'),
            'filtre_statut' => $request->input('statut'),
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
                'unlock_at' => $p->unlock_at?->format(self::DATE_FORMAT),
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
