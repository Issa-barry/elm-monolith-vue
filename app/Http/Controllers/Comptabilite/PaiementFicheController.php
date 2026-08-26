<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\ModePaiement;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutValidationEquipe;
use App\Http\Controllers\Controller;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Services\CommissionAdjustmentService;
use App\Services\CommissionStatusResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaiementFicheController extends Controller
{
    public function indexLivreurs(Request $request): Response
    {
        return $this->renderIndex($request, 'livreur');
    }

    public function indexProprietaires(Request $request): Response
    {
        return $this->renderIndex($request, 'proprietaire');
    }

    public function indexSalaries(Request $request): Response
    {
        return $this->renderIndex($request, 'salarie');
    }

    private function renderIndex(Request $request, string $type): Response
    {
        $this->authorize('viewAny', PaiementFiche::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['site_id', 'statut', 'periode_id', 'search']);

        $query = $this->buildQuery($orgId, $type, $filters)->with(['site', 'periode']);

        // "Équipe" (véhicule) non applicable aux salariés : ni statut de validation
        // par bénéficiaire, ni colonne "Véhicule(s)" (un salarié n'est rattaché à
        // aucun véhicule) — on évite le eager load des lignes/source pour ce type.
        if ($type !== 'salarie') {
            $query->with(['lignes.source' => $this->ligneSourceEagerLoad()]);
        }

        $fiches = $query->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $teamStatusParPeriode = collect();
        if ($type !== 'salarie') {
            $periodesUniques = $fiches->getCollection()->pluck('periode')->filter()->unique('id');
            foreach ($periodesUniques as $p) {
                $teamStatusParPeriode[$p->id] = CommissionAdjustmentService::statutValidationCombineParBeneficiaire($p);
            }
        }

        $fiches->through(fn (PaiementFiche $f) => $this->transform($f, $teamStatusParPeriode));

        $statsQuery = $this->buildQuery($orgId, $type, $filters);
        $stats = (clone $statsQuery)
            ->selectRaw(
                'COUNT(*) as total,
                COALESCE(SUM(montant_net), 0) as total_net,
                COALESCE(SUM(montant_paye), 0) as total_paye,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as nb_a_payer,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as nb_partiellement_paye,
                SUM(CASE WHEN statut = ? THEN 1 ELSE 0 END) as nb_paye',
                [
                    StatutFichePaiement::A_PAYER->value,
                    StatutFichePaiement::PARTIELLEMENT_PAYE->value,
                    StatutFichePaiement::PAYE->value,
                ]
            )
            ->first();

        return Inertia::render('Comptabilite/Fiches/Index', [
            'type' => $type,
            'fiches' => $fiches,
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']),
            'periodes' => PaiementPeriode::forOrg($orgId)
                ->where('type', $type)
                ->orderByDesc('date_debut')
                ->get(['id', 'reference']),
            'statuts' => StatutFichePaiement::options(),
            'filters' => $filters,
            'stats' => [
                'nb_a_payer' => (int) $stats->nb_a_payer,
                'nb_partiellement_paye' => (int) $stats->nb_partiellement_paye,
                'nb_paye' => (int) $stats->nb_paye,
                'total_net' => (float) $stats->total_net,
                'total_paye' => (float) $stats->total_paye,
            ],
        ]);
    }

    public function show(PaiementFiche $fiche): Response
    {
        $this->authorize('view', $fiche);

        $fiche->load([
            'lignes.source' => $this->ligneSourceEagerLoad(),
            'site', 'periode', 'payeur', 'historiquePaiements.createur',
        ]);

        $teamStatusParPeriode = collect();
        if ($fiche->beneficiaire_type !== 'salarie' && $fiche->periode !== null) {
            $teamStatusParPeriode[$fiche->periode->id] = CommissionAdjustmentService::statutValidationCombineParBeneficiaire($fiche->periode);
        }

        return Inertia::render('Comptabilite/Fiches/Show', [
            'fiche' => [
                ...$this->transform($fiche, $teamStatusParPeriode),
                'periode' => $fiche->periode ? [
                    'id' => $fiche->periode->id,
                    'reference' => $fiche->periode->reference,
                    'date_debut' => $fiche->periode->date_debut?->toDateString(),
                    'date_fin' => $fiche->periode->date_fin?->toDateString(),
                ] : null,
                'commentaires' => $fiche->commentaires,
                'signature_path' => $fiche->signature_path,
                'lignes' => $fiche->lignes->map(fn ($l) => [
                    'id' => $l->id,
                    'type_ligne' => $l->type_ligne?->value,
                    'type_label' => $l->type_ligne?->label(),
                    'libelle' => $l->libelle,
                    'montant' => (float) $l->montant,
                    'is_gain' => $l->isGain(),
                    'is_deduction' => $l->isDeduction(),
                    'vehicule' => $this->vehiculeFromLigne($l),
                ]),
                'historique' => $fiche->historiquePaiements->map(fn ($p) => [
                    'id' => $p->id,
                    'montant' => (float) $p->montant,
                    'mode_paiement' => $p->mode_paiement,
                    'date_paiement' => $p->date_paiement?->toDateString(),
                    'note' => $p->note,
                    'createur' => $p->createur?->name,
                ]),
            ],
            'modes_paiement' => ModePaiement::options(),
            'can_payer' => auth()->user()->can('payer', $fiche),
        ]);
    }

    public function exportPdf(PaiementFiche $fiche)
    {
        $this->authorize('view', $fiche);

        $fiche->load(['lignes', 'site', 'periode']);
        $org = Organization::find($fiche->organization_id);

        $pdf = Pdf::loadView('pdf.paiement_fiche', [
            'fiche' => $fiche,
            'org' => $org,
            'generated_at' => now(),
            'printed_by' => auth()->user()->name,
        ])->setPaper('a4');

        return $pdf->download('fiche-'.$fiche->reference.'.pdf');
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', PaiementFiche::class);

        $orgId = auth()->user()->organization_id;
        $type = $request->input('type');
        $filters = $request->only(['site_id', 'statut', 'periode_id', 'search']);

        $query = PaiementFiche::where('organization_id', $orgId);
        if (! auth()->user()->isAdmin()) {
            $siteIds = auth()->user()->sites()->pluck('sites.id')->all();
            $query->whereIn('site_id', $siteIds);
        }
        if ($type) {
            $query->where('beneficiaire_type', $type);
        }
        $this->applyFilters($query, $filters);

        $fiches = $query->with(['site', 'periode', 'payeur'])->orderBy('beneficiaire_nom')->get();

        $filename = 'fiches-paiement-'.($type ?? 'toutes').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($fiches) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Agence', 'Période', 'Référence', 'Type bénéficiaire', 'Nom bénéficiaire',
                'Montant brut', 'Déductions', 'Net à payer', 'Statut',
                'Date paiement', 'Mode paiement', 'Payé par', 'Signature',
            ], ';');

            foreach ($fiches as $f) {
                fputcsv($handle, [
                    $f->site?->nom ?? '',
                    $f->periode?->reference ?? '',
                    $f->reference,
                    $f->beneficiaire_type,
                    $f->beneficiaire_nom,
                    number_format((float) $f->montant_brut, 0, ',', ' '),
                    number_format((float) $f->total_deductions, 0, ',', ' '),
                    number_format((float) $f->montant_net, 0, ',', ' '),
                    $f->statut?->label() ?? '',
                    $f->date_paiement?->format('d/m/Y') ?? '',
                    $f->mode_paiement ?? '',
                    $f->payeur?->name ?? '',
                    '',
                ], ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function buildQuery(string $orgId, string $type, array $filters): Builder
    {
        $query = PaiementFiche::where('organization_id', $orgId)
            ->where('beneficiaire_type', $type);

        if (! auth()->user()->isAdmin()) {
            $siteIds = auth()->user()->sites()->pluck('sites.id')->all();
            $query->whereIn('site_id', $siteIds);
        }

        $this->applyFilters($query, $filters);

        return $query;
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }
        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }
        if (! empty($filters['periode_id'])) {
            $query->where('periode_id', $filters['periode_id']);
        }
        if (! empty($filters['search'])) {
            $query->where('beneficiaire_nom', 'like', '%'.$filters['search'].'%');
        }
    }

    /** @param  Collection<string, array<string, StatutValidationEquipe>>  $teamStatusParPeriode  indexé par periode_id */
    private function transform(PaiementFiche $f, ?Collection $teamStatusParPeriode = null): array
    {
        $teamStatus = null;
        if ($teamStatusParPeriode !== null && $f->periode !== null) {
            $teamStatus = $teamStatusParPeriode[$f->periode->id]["{$f->beneficiaire_type}:{$f->beneficiaire_id}"] ?? null;
        }

        $resolved = CommissionStatusResolver::resolve(
            $f->periode,
            $teamStatus,
            $f->statut?->value ?? '',
            $f->statut?->label() ?? '',
        );

        return [
            'id' => $f->id,
            'reference' => $f->reference,
            'beneficiaire_type' => $f->beneficiaire_type,
            'beneficiaire_nom' => $f->beneficiaire_nom,
            'site' => $f->site ? ['id' => $f->site->id, 'nom' => $f->site->nom] : null,
            'periode_reference' => $f->periode?->reference,
            'periode_id' => $f->periode_id,
            'montant_brut' => (float) $f->montant_brut,
            'total_deductions' => (float) $f->total_deductions,
            'montant_net' => (float) $f->montant_net,
            'montant_paye' => (float) $f->montant_paye,
            'montant_restant' => $f->montant_restant,
            'remaining_amount' => $f->montant_restant,
            'statut' => $f->statut?->value,
            'statut_label' => $f->statut?->label(),
            'mode_paiement' => $f->mode_paiement,
            'date_paiement' => $f->date_paiement?->toDateString(),
            'vehicules' => $f->relationLoaded('lignes') ? $this->vehiculesFromLignes($f->lignes) : [],
            ...$resolved,
        ];
    }

    /**
     * Callback d'eager load pour PaiementFicheLigne::source() (morphTo) — ne
     * charge la relation véhicule que pour les types de source qui en portent
     * une (CommissionLogistiquePart/CommissionEnveloppePart via leur commission,
     * Depense imputée à un véhicule). Partagé entre show() et renderIndex()
     * pour garder les deux vues cohérentes (cf. audit UX bénéficiaire/véhicule).
     */
    private function ligneSourceEagerLoad(): \Closure
    {
        return function (MorphTo $morphTo) {
            $morphTo->morphWith([
                CommissionLogistiquePart::class => ['commission.vehicule'],
                CommissionEnveloppePart::class => ['enveloppe.source.vehicule'],
                Depense::class => ['vehiculeBeneficiaire'],
            ]);
        };
    }

    /** Résout le véhicule à l'origine d'une ligne de fiche, si applicable. */
    private function vehiculeFromLigne(PaiementFicheLigne $ligne): ?array
    {
        $source = $ligne->source;

        $vehicule = match (true) {
            $source instanceof CommissionLogistiquePart => $source->commission?->vehicule,
            $source instanceof CommissionEnveloppePart => $source->enveloppe?->source?->vehicule,
            $source instanceof Depense && $source->beneficiaire_type === 'vehicule' => $source->vehiculeBeneficiaire,
            default => null,
        };

        if (! $vehicule) {
            return null;
        }

        return [
            'id' => $vehicule->id,
            'nom' => $vehicule->nom_vehicule,
            'immatriculation' => $vehicule->immatriculation,
        ];
    }

    /**
     * Liste des véhicules distincts référencés par les lignes d'une fiche —
     * une fiche (bénéficiaire + période) peut agréger des gains issus de
     * plusieurs véhicules : le bénéficiaire ne doit jamais rester ambigu
     * quant à son véhicule de rattachement.
     *
     * @param  Collection<int, PaiementFicheLigne>  $lignes
     * @return array<int, array{id: string, nom: string, immatriculation: ?string}>
     */
    private function vehiculesFromLignes(Collection $lignes): array
    {
        return $lignes
            ->map(fn (PaiementFicheLigne $l) => $this->vehiculeFromLigne($l))
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }
}
