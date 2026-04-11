<?php

namespace App\Http\Controllers;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommissionLogistique;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\TransfertLogistique;
use App\Services\CommissionLogistiqueService;
use App\Services\TransfertActiviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CommissionLogistiqueController extends Controller
{
    private const DATE_FORMAT = 'd/m/Y';

    // ── Index : liste autonome des commissions logistiques ────────────────────

    /**
     * GET /logistique/commissions
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        $user    = auth()->user();
        $orgId   = $user->organization_id;
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin_entreprise']);
        $siteIds = $isAdmin ? collect() : $user->sites()->pluck('sites.id');

        $query = CommissionLogistique::with([
            'transfert:id,reference,site_source_id,site_destination_id,statut',
            'transfert.siteSource:id,nom',
            'transfert.siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'parts',
        ])->where('organization_id', $orgId);

        // Non-admins : uniquement les commissions des transferts impliquant leurs sites
        if (! $isAdmin && $siteIds->isNotEmpty()) {
            $query->whereHas('transfert', function ($q) use ($siteIds) {
                $q->where(function ($sub) use ($siteIds) {
                    $sub->whereIn('site_source_id', $siteIds)
                        ->orWhereIn('site_destination_id', $siteIds);
                });
            });
        }

        // Filtre statut
        if ($statut = $request->input('statut')) {
            $query->where('statut', $statut);
        }

        // Filtre référence transfert
        if ($ref = $request->input('reference')) {
            $query->whereHas('transfert', fn ($q) => $q->where('reference', 'like', "%{$ref}%"));
        }

        $commissions = $query->orderByDesc('created_at')->get();

        $actives = $commissions->filter(fn ($c) => ! $c->isAnnulee());

        $kpis = [
            'total'                 => $commissions->count(),
            'montant_total'         => (float) $actives->sum('montant_total'),
            'montant_verse'         => (float) $actives->sum('montant_verse'),
            'montant_restant'       => (float) $actives->sum('montant_restant'),
            'nb_en_attente'         => $commissions->where('statut', StatutCommissionLogistique::EN_ATTENTE)->count(),
            'nb_partiellement'      => $commissions->where('statut', StatutCommissionLogistique::PARTIELLEMENT_VERSEE)->count(),
            'nb_versees'            => $commissions->where('statut', StatutCommissionLogistique::VERSEE)->count(),
        ];

        return Inertia::render('Logistique/Commissions/Index', [
            'commissions'     => $commissions->map(fn ($c) => $this->mapIndex($c))->values(),
            'kpis'            => $kpis,
            'statuts'         => StatutCommissionLogistique::options(),
            'filtre_statut'   => $request->input('statut'),
            'filtre_reference'=> $request->input('reference'),
        ]);
    }

    // ── Show : détail commission ──────────────────────────────────────────────

    /**
     * GET /logistique/commissions/{commission_logistique}
     */
    public function show(CommissionLogistique $commission_logistique): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        abort_unless(
            $commission_logistique->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $commission_logistique->load([
            'transfert:id,organization_id,reference,site_source_id,site_destination_id,statut',
            'transfert.siteSource:id,nom',
            'transfert.siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'parts.versements.createur:id,prenom,nom',
        ]);

        $user      = auth()->user();
        $transfert = $commission_logistique->transfert;

        return Inertia::render('Logistique/Commissions/Show', [
            'commission'     => $this->mapDetail($commission_logistique),
            'modes_paiement' => [
                ['value' => 'especes',      'label' => 'Espèces'],
                ['value' => 'virement',     'label' => 'Virement'],
                ['value' => 'cheque',       'label' => 'Chèque'],
                ['value' => 'mobile_money', 'label' => 'Mobile Money'],
            ],
            'can_verser' => $transfert
                ? $user->can('verserCommission', $transfert)
                : false,
        ]);
    }

    // ── Génération commission ─────────────────────────────────────────────────

    /**
     * POST /logistique/{transfert}/commission
     */
    public function store(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('genererCommission', $transfert_logistique);

        $baseCalcul = $request->input('base_calcul');

        $data = $request->validate([
            'base_calcul'        => ['required', Rule::in(array_column(BaseCalculLogistique::cases(), 'value'))],
            'valeur_base'        => ['required', 'numeric', 'min:0'],
            'quantite_reference' => [
                'nullable',
                'integer',
                'min:1',
                Rule::requiredIf(in_array($baseCalcul, [
                    BaseCalculLogistique::PAR_PACK->value,
                    BaseCalculLogistique::PAR_KM->value,
                ])),
            ],
        ], [
            'base_calcul.required'        => 'La base de calcul est obligatoire.',
            'base_calcul.in'              => 'Base de calcul invalide.',
            'valeur_base.required'        => 'La valeur de base est obligatoire.',
            'valeur_base.min'             => 'La valeur de base doit être positive.',
            'quantite_reference.required' => 'La quantité de référence est obligatoire pour ce mode de calcul.',
            'quantite_reference.min'      => 'La quantité de référence doit être supérieure à 0.',
        ]);

        try {
            CommissionLogistiqueService::genererPourTransfert(
                $transfert_logistique,
                $data['base_calcul'],
                (float) $data['valeur_base'],
                isset($data['quantite_reference']) ? (int) $data['quantite_reference'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['commission' => $e->getMessage()]);
        }

        TransfertActiviteService::log($transfert_logistique, 'commission_generee');

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Commission générée avec succès.');
    }

    // ── Mappings privés ───────────────────────────────────────────────────────

    private function mapIndex(CommissionLogistique $c): array
    {
        return [
            'id'                    => $c->id,
            'transfert_id'          => $c->transfert_logistique_id,
            'transfert_reference'   => $c->transfert?->reference,
            'site_source_nom'       => $c->transfert?->siteSource?->nom,
            'site_destination_nom'  => $c->transfert?->siteDestination?->nom,
            'vehicule_nom'          => $c->vehicule?->nom_vehicule,
            'immatriculation'       => $c->vehicule?->immatriculation,
            'base_calcul_label'     => $c->base_calcul?->label() ?? '—',
            'montant_total'         => (float) $c->montant_total,
            'montant_verse'         => (float) $c->montant_verse,
            'montant_restant'       => (float) $c->montant_restant,
            'statut'                => $c->statut?->value,
            'statut_label'          => $c->statut_label,
            'statut_dot_class'      => $c->statut_dot_class,
            'nb_parts'              => $c->parts->count(),
            'created_at'            => $c->created_at?->format(self::DATE_FORMAT),
        ];
    }

    private function mapDetail(CommissionLogistique $c): array
    {
        return [
            'id'                    => $c->id,
            'transfert_id'          => $c->transfert_logistique_id,
            'transfert_reference'   => $c->transfert?->reference,
            'transfert_statut'      => $c->transfert?->statut?->value,
            'transfert_statut_label'=> $c->transfert?->statut_label,
            'site_source_nom'       => $c->transfert?->siteSource?->nom,
            'site_destination_nom'  => $c->transfert?->siteDestination?->nom,
            'vehicule_nom'          => $c->vehicule?->nom_vehicule,
            'immatriculation'       => $c->vehicule?->immatriculation,
            'base_calcul_label'     => $c->base_calcul?->label() ?? '—',
            'valeur_base'           => (float) $c->valeur_base,
            'quantite_reference'    => $c->quantite_reference,
            'montant_total'         => (float) $c->montant_total,
            'montant_verse'         => (float) $c->montant_verse,
            'montant_restant'       => (float) $c->montant_restant,
            'statut'                => $c->statut?->value,
            'statut_label'          => $c->statut_label,
            'statut_dot_class'      => $c->statut_dot_class,
            'is_versee'             => $c->isVersee(),
            'parts'                 => $c->parts->map(fn (CommissionLogistiquePart $p) => [
                'id'                    => $p->id,
                'type_beneficiaire'     => $p->type_beneficiaire,
                'beneficiaire_nom'      => $p->beneficiaire_nom,
                'taux_commission'       => (float) $p->taux_commission,
                'montant_brut'          => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net'           => (float) $p->montant_net,
                'montant_verse'         => (float) $p->montant_verse,
                'montant_restant'       => (float) $p->montant_restant,
                'statut'                => $p->statut?->value,
                'statut_label'          => $p->statut_label,
                'statut_dot_class'      => $p->statut_dot_class,
                'is_versee'             => $p->isVersee(),
                'versements'            => $p->versements->sortByDesc('created_at')->values()->map(fn ($v) => [
                    'id'            => $v->id,
                    'montant'       => (float) $v->montant,
                    'date_versement'=> $v->date_versement?->format(self::DATE_FORMAT),
                    'mode_paiement' => $v->mode_paiement,
                    'note'          => $v->note,
                    'created_by'    => $v->createur
                        ? trim($v->createur->prenom . ' ' . $v->createur->nom)
                        : null,
                    'created_at'    => $v->created_at?->format('d/m/Y H:i'),
                ])->all(),
            ])->values()->all(),
            'created_at'            => $c->created_at?->format(self::DATE_FORMAT),
        ];
    }
}
