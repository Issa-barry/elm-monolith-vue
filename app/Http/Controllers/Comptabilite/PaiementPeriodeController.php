<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PaiementPeriode;
use App\Services\AuditLogService;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaiementPeriodeController extends Controller
{
    public function __construct(
        private PeriodeCalculatorService $calculator,
        private PeriodePaiementService $periodes,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaiementPeriode::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['type', 'statut', 'annee', 'mois', 'quinzaine', 'search']);

        // Le cycle courant s'applique aux 3 types en même temps (même quinzaine) : on
        // s'assure que la période "en cours" de chaque type existe déjà, pour que le
        // tableau de bord puisse toujours l'afficher sans jamais avoir à la créer
        // manuellement.
        $courantes = collect(TypePeriodePaiement::cases())
            ->mapWithKeys(fn (TypePeriodePaiement $type) => [
                $type->value => $this->periodes->getCurrentPeriod($orgId, $type, auth()->id()),
            ]);

        $now = Carbon::now();
        $quinzaineCourante = PeriodePaiementService::quinzaineForDate($now);
        $dateSuivante = Carbon::parse($courantes->first()->date_fin)->addDay();

        $query = PaiementPeriode::forOrg($orgId)->with('site');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }
        if (! empty($filters['annee'])) {
            $annee = (int) $filters['annee'];
            $query->whereBetween('date_debut', ["{$annee}-01-01", "{$annee}-12-31"]);
        }
        if (! empty($filters['mois'])) {
            $query->whereMonth('date_debut', (int) $filters['mois']);
        }
        if (! empty($filters['quinzaine']) && in_array($filters['quinzaine'], [PeriodePaiementService::P1, PeriodePaiementService::P2], true)) {
            if ($filters['quinzaine'] === PeriodePaiementService::P1) {
                $query->whereDay('date_debut', '<=', 15);
            } else {
                $query->whereDay('date_debut', '>', 15);
            }
        }
        if (! empty($filters['search'])) {
            $s = mb_strtolower(trim($filters['search']));
            $query->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', ["%{$s}%"]));
        }

        // Tri Année DESC, Mois DESC, P2 avant P1 : garanti par date_debut DESC seul,
        // puisque le 16 (P2) est toujours postérieur au 1er (P1) du même mois.
        $periodes = $query->orderByDesc('date_debut')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PaiementPeriode $p) => $this->transform($p));

        return Inertia::render('Comptabilite/Periodes/Index', [
            'periodes' => $periodes,
            'types' => TypePeriodePaiement::options(),
            'statuts' => StatutPeriodePaiement::options(),
            'filters' => $filters,
            'cycle' => [
                'annee_courante' => $now->year,
                'periode_courante_label' => PeriodePaiementService::labelFor($now->year, $now->month, $quinzaineCourante),
                'periode_suivante_label' => PeriodePaiementService::labelFor($dateSuivante->year, $dateSuivante->month, PeriodePaiementService::quinzaineForDate($dateSuivante)),
                'par_type' => collect(TypePeriodePaiement::cases())->map(fn (TypePeriodePaiement $type) => [
                    'type' => $type->value,
                    'type_label' => $type->label(),
                    'periode' => $this->transform($courantes[$type->value]),
                ])->values(),
            ],
        ]);
    }

    /**
     * Résout (et crée si nécessaire) la période correspondant à un type/année/mois/quinzaine,
     * puis redirige vers sa fiche détaillée. Permet de consulter une période qui n'existe pas
     * encore sans jamais passer par une création manuelle.
     */
    public function voir(string $type, int $annee, int $mois, string $quinzaine): RedirectResponse
    {
        $this->authorize('viewAny', PaiementPeriode::class);

        $data = validator(
            ['type' => $type, 'quinzaine' => $quinzaine, 'mois' => $mois],
            [
                'type' => ['required', Rule::in(TypePeriodePaiement::values())],
                'quinzaine' => ['required', Rule::in([PeriodePaiementService::P1, PeriodePaiementService::P2])],
                'mois' => ['required', 'integer', 'between:1,12'],
            ],
        )->validate();

        [$debut] = PeriodePaiementService::dateRangeFor($annee, $mois, $data['quinzaine']);

        $periode = $this->periodes->getOrCreatePeriod(
            auth()->user()->organization_id,
            TypePeriodePaiement::from($data['type']),
            $debut,
            auth()->id(),
        );

        return redirect()->route('comptabilite.periodes.show', $periode);
    }

    public function show(PaiementPeriode $periode, Request $request): Response
    {
        $this->authorize('view', $periode);

        $periode->load('site', 'createur', 'validateur');

        $allFiches = $periode->fiches()->get();

        $filters = $request->only(['vehicule', 'livreur', 'proprietaire', 'etat']);

        // Le détail de période est centré véhicule : c'est ainsi que le métier travaille
        // (aucun concept de véhicule pour les périodes salarié).
        $vehicules = [];
        if (in_array($periode->type, [TypePeriodePaiement::LIVREUR, TypePeriodePaiement::PROPRIETAIRE], true)) {
            $vehicules = collect(CommissionAdjustmentService::vehiculesParPeriode($periode));

            if (array_filter($filters)) {
                $beneficiairesParVehicule = collect(CommissionAdjustmentService::groupesParCommission($periode))
                    ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
                    ->map(fn ($groupes) => $groupes->flatMap(fn (array $g) => $g['parts'])->pluck('beneficiaire_nom'));

                $vehicules = $vehicules->filter(function (array $v) use ($filters, $beneficiairesParVehicule) {
                    if (! empty($filters['vehicule'])) {
                        $needle = mb_strtolower(trim($filters['vehicule']));
                        if (! str_contains(mb_strtolower($v['vehicule_nom']), $needle) && ! str_contains(mb_strtolower($v['vehicule_immat'] ?? ''), $needle)) {
                            return false;
                        }
                    }
                    if (! empty($filters['etat'])) {
                        if ($v['equilibre'] !== ($filters['etat'] === 'valide')) {
                            return false;
                        }
                    }
                    if (! empty($filters['livreur']) || ! empty($filters['proprietaire'])) {
                        $needle = mb_strtolower(trim($filters['livreur'] ?? $filters['proprietaire']));
                        $noms = $beneficiairesParVehicule->get($v['vehicule_id'] ?? '__sans_vehicule__', collect());
                        if (! $noms->contains(fn (string $n) => str_contains(mb_strtolower($n), $needle))) {
                            return false;
                        }
                    }

                    return true;
                });
            }

            $vehicules = $vehicules->values()->all();
        }

        return Inertia::render('Comptabilite/Periodes/Show', [
            'periode' => $this->transform($periode),
            'vehicules' => $vehicules,
            'filters' => $filters,
            'stats' => [
                'total_brut' => (float) $allFiches->sum('montant_brut'),
                'total_net' => (float) $allFiches->sum('montant_net'),
                'total_paye' => (float) $allFiches->sum('montant_paye'),
                'reste' => max(0.0, (float) $allFiches->sum('montant_net') - (float) $allFiches->sum('montant_paye')),
            ],
            'can' => [
                'calculer' => auth()->user()->can('calculer', $periode),
                'valider' => auth()->user()->can('valider', $periode),
                'cloturer' => auth()->user()->can('cloturer', $periode),
                'delete' => auth()->user()->can('delete', $periode),
                'ajuster' => auth()->user()->can('ajuster', $periode),
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

        return back()->with('success', "{$n} fiche".($n > 1 ? 's' : '').' générée'.($n > 1 ? 's' : '').' avec succès.');
    }

    public function valider(PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('valider', $periode);

        $nonValidees = CommissionAdjustmentService::partsNonValidees($periode);
        if ($nonValidees->isNotEmpty()) {
            $n = $nonValidees->count();

            return back()->with('error', "{$n} commission".($n > 1 ? 's' : '').' non validée'.($n > 1 ? 's' : '').". Passez par l'écran d'ajustement avant de valider la période.");
        }

        $resume = CommissionAdjustmentService::resumeEcarts($periode);
        if (! empty($resume['par_vehicule'])) {
            $ecart = $resume['ecart'];
            $abs = number_format(abs($ecart), 0, ',', ' ');
            $n = count($resume['par_vehicule']);
            $sens = $ecart < 0 ? "il reste {$abs} GNF à redistribuer" : "le montant ajusté dépasse de {$abs} GNF le montant théorique";

            return back()->with('error', "Impossible de valider : {$sens} sur {$n} véhicule(s). La somme des montants ajustés doit toujours égaler la somme des montants théoriques, véhicule par véhicule sur l'ensemble de la période. Passez par l'écran d'ajustement.");
        }

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
            'quinzaine' => $p->date_debut ? PeriodePaiementService::quinzaineForDate($p->date_debut) : null,
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
}
