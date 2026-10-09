<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Controller;
use App\Models\CommissionEnveloppePart;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use App\Services\PeriodeValidationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        // Le cycle courant s'applique à tous les types en même temps (même quinzaine) : on
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

        // Par défaut (aucun filtre choisi par l'utilisateur), on se place sur le cycle en
        // cours plutôt que d'afficher tout l'historique : c'est ce que l'utilisateur vient
        // consulter la plupart du temps, et les sélecteurs reflètent alors cet état.
        if (empty($filters['annee'])) {
            $filters['annee'] = (string) $now->year;
        }
        if (empty($filters['mois'])) {
            $filters['mois'] = (string) $now->month;
        }
        if (empty($filters['quinzaine'])) {
            $filters['quinzaine'] = $quinzaineCourante;
        }

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

        // Auto-calcul à l'ouverture de la page : si les fiches n'existent pas encore ou si les
        // données source (commissions/dépenses/paie) ont changé depuis le dernier calcul, on
        // (re)génère avant de construire la réponse, pour que l'écran affiche toujours des
        // montants à jour sans action manuelle. Idempotent et sans doublon (cf.
        // PeriodeCalculatorService::calculer()). Ne s'applique jamais à une période
        // validée/clôturée : needsRecalcul() renvoie alors toujours false, ses montants
        // restent figés tant qu'elle n'a pas été repassée en brouillon.
        // Période validée ayant reçu des commissions depuis : réouverte avant recalcul, même
        // déjà payée (fiche complémentaire, ADR 0010), cf. PeriodeValidationService.
        app(PeriodeValidationService::class)->rouvrirSiDesynchronisee($periode);
        $recalcul = $this->calculator->calculerSiNecessaire($periode->refresh());
        if ($recalcul['recalcule']) {
            $periode->refresh();
        }

        $allFiches = $periode->fiches()->get();

        $filters = $request->only(['vehicule', 'type_vehicule_id', 'livreur', 'proprietaire', 'nb_membres', 'etat', 'beneficiaire']);

        // Le détail de période est centré véhicule pour livreur/propriétaire : c'est ainsi que
        // le métier travaille pour ces deux types (une commission de vente/logistique s'ancre
        // sur un véhicule). Pour les autres types (salarié, site, consultant), il n'existe aucun
        // concept de véhicule — chaque PaiementFiche EST directement la ligne bénéficiaire à
        // afficher, sans regroupement supplémentaire.
        $beneficiaires = [];
        $vehicules = [];
        $typesVehicule = [];
        if (in_array($periode->type, [TypePeriodePaiement::LIVREUR, TypePeriodePaiement::PROPRIETAIRE], true)) {
            // Toujours combiner vente + logistique (jamais un choix) : un même
            // véhicule/bénéficiaire peut porter les deux natures de commission sur la période.
            $vehicules = $this->avecDetailsVehicule($this->avecMontantsPayes(
                collect(CommissionAdjustmentService::vehiculesParPeriodeCombine($periode)),
                $periode,
            ), $periode);

            // Options issues de toute la période, avant les filtres : la sélection
            // d'un type ne fait pas disparaître les autres choix.
            $typesVehicule = $vehicules
                ->filter(fn (array $v) => $v['type_vehicule_id'] !== null)
                ->unique('type_vehicule_id')
                ->sortBy('type_vehicule_nom')
                ->map(fn (array $v) => ['value' => $v['type_vehicule_id'], 'label' => $v['type_vehicule_nom']])
                ->values()->all();

            if (array_filter($filters)) {
                $beneficiairesParVehicule = collect([
                    ...CommissionAdjustmentService::groupesParCommission($periode),
                    ...CommissionAdjustmentService::groupesLogistiqueParCommission($periode),
                ])
                    ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
                    ->map(fn ($groupes) => $groupes->flatMap(fn (array $g) => $g['parts'])
                        ->map(fn ($p) => $p instanceof CommissionEnveloppePart
                            ? $p->resoudreBeneficiaire()?->nom_complet
                            : $p->beneficiaire_nom)
                        ->filter());

                $vehicules = $vehicules->filter(function (array $v) use ($filters, $beneficiairesParVehicule) {
                    if (! empty($filters['type_vehicule_id']) && $v['type_vehicule_id'] !== $filters['type_vehicule_id']) {
                        return false;
                    }
                    if (! empty($filters['vehicule'])) {
                        $needle = mb_strtolower(trim($filters['vehicule']));
                        if (! str_contains(mb_strtolower($v['vehicule_nom']), $needle) && ! str_contains(mb_strtolower($v['vehicule_immat'] ?? ''), $needle)) {
                            return false;
                        }
                    }
                    if (! empty($filters['etat'])) {
                        if ($v['statut_validation'] !== $filters['etat']) {
                            return false;
                        }
                    }
                    // Même valeur que la colonne « Membres » : bénéficiaires commissionnés sur
                    // la période, pas la taille actuelle de l'équipe.
                    if (! empty($filters['nb_membres']) && $v['nb_membres'] !== (int) $filters['nb_membres']) {
                        return false;
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
        } else {
            $beneficiaires = $allFiches
                ->when(! empty($filters['beneficiaire']), fn ($c) => $c->filter(
                    fn (PaiementFiche $f) => str_contains(mb_strtolower($f->beneficiaire_nom ?? ''), mb_strtolower(trim($filters['beneficiaire'])))
                ))
                ->when(! empty($filters['etat']), fn ($c) => $c->filter(
                    fn (PaiementFiche $f) => $f->statut?->value === $filters['etat']
                ))
                ->map(fn (PaiementFiche $f) => [
                    'fiche_id' => $f->id,
                    'beneficiaire_nom' => $f->beneficiaire_label,
                    'montant_brut' => (float) $f->montant_brut,
                    'montant_net' => (float) $f->montant_net,
                    'montant_paye' => (float) $f->montant_paye,
                    'reste' => $f->montant_restant,
                    'statut' => $f->statut?->value,
                    'statut_label' => $f->statut_label,
                ])
                ->sortBy('beneficiaire_nom')
                ->values()
                ->all();
        }

        return Inertia::render('Comptabilite/Periodes/Show', [
            'periode' => $this->transform($periode),
            'vehicules' => $vehicules,
            'typesVehicule' => $typesVehicule,
            'beneficiaires' => $beneficiaires,
            'filters' => $filters,
            'recalcul' => [
                'effectue' => $recalcul['recalcule'],
                'nb_fiches' => $recalcul['nb_fiches'],
            ],
            'stats' => $this->stats($allFiches, $filters, $vehicules, $beneficiaires, $periode),
            'validation' => $this->etatValidation($periode),
            'can' => [
                'calculer' => auth()->user()->can('calculer', $periode),
                'valider' => auth()->user()->can('gererValidation', $periode),
                'cloturer' => auth()->user()->can('cloturer', $periode),
                'delete' => auth()->user()->can('delete', $periode),
                'ajuster' => auth()->user()->can('ajuster', $periode),
            ],
        ]);
    }

    /**
     * Cartes de synthèse. Sans filtre : totaux des fiches de la période. Avec un filtre actif :
     * somme des lignes affichées (véhicules ou bénéficiaires), pour que les cartes décrivent
     * toujours ce que montre le tableau. Le reste de toute la période reste exposé à part.
     *
     * @param  Collection<int, PaiementFiche>  $allFiches
     * @param  list<array>  $vehicules
     * @param  list<array>  $beneficiaires
     */
    private function stats(Collection $allFiches, array $filters, array $vehicules, array $beneficiaires, PaiementPeriode $periode): array
    {
        $totalNet = (float) $allFiches->sum('montant_net');
        $totalPaye = (float) $allFiches->sum('montant_paye');
        $restePeriode = max(0.0, $totalNet - $totalPaye);

        if (! array_filter($filters)) {
            return [
                'filtre' => false,
                'nb_lignes' => null,
                'total_brut' => (float) $allFiches->sum('montant_brut'),
                'total_net' => $totalNet,
                'total_paye' => $totalPaye,
                'reste' => $restePeriode,
                'reste_periode' => $restePeriode,
            ];
        }

        $estVehicule = in_array($periode->type, [TypePeriodePaiement::LIVREUR, TypePeriodePaiement::PROPRIETAIRE], true);
        $lignes = collect($estVehicule ? $vehicules : $beneficiaires);

        return [
            'filtre' => true,
            'nb_lignes' => $lignes->count(),
            'total_brut' => round((float) $lignes->sum($estVehicule ? 'theorique' : 'montant_brut'), 2),
            'total_net' => round((float) $lignes->sum($estVehicule ? 'ajuste' : 'montant_net'), 2),
            'total_paye' => round((float) $lignes->sum($estVehicule ? 'deja_paye' : 'montant_paye'), 2),
            'reste' => round((float) $lignes->sum('reste'), 2),
            'reste_periode' => $restePeriode,
        ];
    }

    /**
     * Enrichit chaque véhicule avec le montant déjà payé et le reste à payer. Le déjà payé est
     * la somme des montants versés sur les parts de commission du véhicule (`montant_verse`,
     * alimenté par l'allocation des paiements de fiche), et non le total payé de la fiche du
     * bénéficiaire : un propriétaire possède souvent plusieurs véhicules, le total de sa fiche
     * serait sinon compté sur chacun d'eux.
     *
     * @param  Collection<int, array>  $vehicules
     * @return Collection<int, array>
     */
    private function avecMontantsPayes(Collection $vehicules, PaiementPeriode $periode): Collection
    {
        if ($vehicules->isEmpty()) {
            return $vehicules;
        }

        $verseParVehicule = collect([
            ...CommissionAdjustmentService::groupesParCommission($periode),
            ...CommissionAdjustmentService::groupesLogistiqueParCommission($periode),
        ])
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
            ->map(fn ($groupes) => (float) $groupes->flatMap(fn (array $g) => $g['parts'])
                ->sum(fn ($p) => (float) $p->montant_verse));

        return $vehicules->map(function (array $v) use ($verseParVehicule) {
            $dejaPaye = (float) $verseParVehicule->get($v['vehicule_id'] ?? '__sans_vehicule__', 0.0);

            $v['deja_paye'] = round($dejaPaye, 2);
            $v['reste'] = max(0.0, round($v['ajuste'] - $v['deja_paye'], 2));

            return $v;
        });
    }

    /**
     * Ajoute `taille_equipe` : nombre de membres de l'équipe active du véhicule (composition
     * actuelle — l'équipe n'est pas historisée), à distinguer de `nb_membres` qui ne compte que
     * les bénéficiaires ayant une commission sur la période. Null sans véhicule ou sans équipe.
     * Ajoute aussi le type et le propriétaire actuel du véhicule (libellé d'affichage + téléphone).
     *
     * @param  Collection<int, array>  $vehicules
     * @return Collection<int, array>
     */
    private function avecDetailsVehicule(Collection $vehicules, PaiementPeriode $periode): Collection
    {
        $details = Vehicule::withTrashed()
            ->where('organization_id', $periode->organization_id)
            ->whereIn('id', $vehicules->pluck('vehicule_id')->filter()->all())
            ->with([
                'typeVehicule' => fn ($query) => $query->withTrashed()->where('organization_id', $periode->organization_id),
                'proprietaire' => fn ($query) => $query->withTrashed()->where('organization_id', $periode->organization_id),
                'proprietaire.personne' => fn ($query) => $query->withTrashed(),
            ])
            ->get(['id', 'type_vehicule_id', 'proprietaire_id'])
            ->keyBy('id');

        $tailles = EquipeLivraison::where('organization_id', $periode->organization_id)
            ->whereIn('vehicule_id', $vehicules->pluck('vehicule_id')->filter()->all())
            ->where('is_active', true)
            ->withCount('membres')
            ->get(['id', 'vehicule_id'])
            ->pluck('membres_count', 'vehicule_id');

        return $vehicules->map(function (array $v) use ($tailles, $details) {
            $v['taille_equipe'] = $v['vehicule_id'] !== null ? $tailles->get($v['vehicule_id']) : null;
            $type = $details->get($v['vehicule_id'])?->typeVehicule;
            $v['type_vehicule_id'] = $type?->id;
            $v['type_vehicule_nom'] = $type?->nom;
            $proprietaire = $details->get($v['vehicule_id'])?->proprietaire;
            $v['proprietaire_nom'] = $proprietaire?->nom_affichage ?: null;
            $v['proprietaire_telephone'] = $proprietaire?->telephone;

            return $v;
        });
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

        // L'état est revérifié par le service (le super administrateur court-circuite la
        // policy via Gate::before) : revalider une période déjà validée réactiverait/
        // recomptabiliserait sans jamais intégrer de nouvelles commissions.
        $erreur = app(PeriodeValidationService::class)->valider($periode, auth()->user());

        return $erreur !== null
            ? back()->with('error', $erreur)
            : back()->with('success', 'Période validée.');
    }

    /**
     * État du bouton « Valider la période de paiement ». Seule une période calculée est
     * validable. Une commission arrivée après la validation rouvre la période (même déjà
     * payée) ; commissions_hors_fiches ne reste donc non nul que sur une période clôturée ou
     * dont la réouverture automatique a échoué : elles y sont seulement signalées.
     *
     * @return array{possible: bool, raison: ?string, commissions_hors_fiches: array{nombre: int, montant: float}}
     */
    private function etatValidation(PaiementPeriode $periode): array
    {
        $horsFiches = $periode->isValidee() || $periode->isCloturee()
            ? $this->calculator->commissionsHorsFiches($periode)
            : ['nombre' => 0, 'montant' => 0.0];

        $raison = match (true) {
            $periode->peutEtreValidee() => null,
            $periode->isBrouillon() => "La période doit d'abord être calculée.",
            $horsFiches['nombre'] > 0 => 'Des commissions arrivées après la validation ne sont sur aucune fiche (voir l\'alerte).',
            default => 'Période déjà validée — aucune nouvelle commission à valider.',
        };

        return [
            'possible' => $periode->peutEtreValidee(),
            'raison' => $raison,
            'commissions_hors_fiches' => $horsFiches,
        ];
    }

    public function cloturer(PaiementPeriode $periode): RedirectResponse
    {
        $this->authorize('cloturer', $periode);

        $resteAPayer = (float) $periode->fiches()->sum('montant_net') - (float) $periode->fiches()->sum('montant_paye');
        if ($resteAPayer > 0.009) {
            return back()->with('error', sprintf(
                'Impossible de clôturer : il reste %s GNF à payer sur cette période.',
                number_format($resteAPayer, 0, ',', ' ')
            ));
        }

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
