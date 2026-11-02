<?php

namespace App\Http\Controllers\Comptabilite;

use App\Contracts\Rh\SourceDonneesPaie;
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
use App\Models\TransmissionPaieLigne;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Comptabilite\PaiementSalaireService;
use App\Services\Comptabilite\ReprisePaieService;
use App\Services\Comptabilite\ValidationPeriodePaieService;
use App\Services\PaieCalculService;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\DecaissementSalaireResolver;
use App\Support\Commission\CommissionSummaryFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    public function __construct(
        private PaieCalculService $paieCalc,
        private SiteScopeService $siteScope,
        private PaiementSalaireService $paiementSalaire,
        private ReprisePaieService $reprise,
        private SourceDonneesPaie $sourcePaie,
        private DecaissementSalaireResolver $decaissement,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        [$moisParDefaut, $anneeParDefaut] = $request->hasAny(['mois', 'annee'])
            ? [now()->month, now()->year]
            : $this->moisParDefaut($user, $orgId);
        $filtreMois = (int) $request->input('mois', $moisParDefaut);
        $filtreAnnee = (int) $request->input('annee', $anneeParDefaut);
        $filtreStatut = (string) $request->input('statut', '');
        $search = trim((string) $request->input('search', ''));

        $sites = $this->sitesFiltrables($user, $orgId);
        $filtreSiteIds = $this->filtreSiteIds($request, $user);

        $periode = $this->paieCalc->getOrCreerPeriode($orgId, $filtreMois, $filtreAnnee);
        $etatReprise = $this->reprise->etat($periode);
        $elementsParSalarie = $this->elementsTransmis($periode);

        // « Payer » n'est proposé que si le serveur l'accepterait : permission, période validée et
        // salarié dans les agences d'écriture de l'utilisateur (règle 3 des standards UI).
        $peutPayerPeriode = $periode !== null && $user->can('pay', $periode);

        $lignes = collect();

        if ($periode) {
            $allLignes = $this->lignesDuPerimetre($periode, $user, $filtreSiteIds, $filtreStatut)
                ->get()
                ->sortBy(fn ($l) => $l->employe?->nom_complet ?? '');

            if ($search !== '') {
                $s = mb_strtolower($search);
                $allLignes = $allLignes->filter(
                    fn ($l) => str_contains(mb_strtolower($l->employe?->nom_complet ?? ''), $s)
                );
            }

            // Données du dialogue de paiement (moyens de l'agence, caisse du payeur, soldes), seulement
            // pour les lignes que l'utilisateur peut réellement payer.
            $tresorerie = $this->decaissement->optionsPourEmployes(
                $peutPayerPeriode
                    ? $allLignes
                        ->filter(fn ($l) => (float) $l->reste_a_payer > 0
                            && $l->employe !== null
                            && $this->paiementSalaire->peutPayerEmploye($l->employe, $user))
                        ->map(fn ($l) => $l->employe)
                        ->unique('id')
                        ->values()
                    : collect(),
                $user,
            );

            $lignes = $allLignes->map(fn ($l) => [
                'id' => $l->id,
                'employe_id' => $l->employe_id,
                'employe_nom' => $l->employe?->nom_complet ?? '—',
                'poste' => $l->contrat?->type_contrat?->label() ?? '—',
                'site' => $l->employe?->site?->nom ?? '—',
                'salaire_base' => (float) $l->salaire_base,
                'brut' => (float) $l->brut,
                'total_primes' => (float) $l->total_primes,
                'total_avantages_nature' => (float) $l->total_avantages_nature,
                'total_avances' => (float) $l->total_avances,
                'total_retenues' => (float) $l->total_retenues,
                'deductions' => (float) $l->deductions,
                'net' => (float) $l->net,
                'deja_paye' => (float) $l->deja_paye,
                'reste_a_payer' => (float) $l->reste_a_payer,
                'statut' => $l->statut?->value ?? 'en_attente',
                'statut_label' => $l->statut?->label() ?? 'En attente',
                'peut_payer' => $peutPayerPeriode
                    && $l->employe !== null
                    && $this->paiementSalaire->peutPayerEmploye($l->employe, $user),
                'tresorerie' => $tresorerie[$l->employe_id] ?? null,
                'elements' => $elementsParSalarie->get($l->employe_id, []),
            ])->values();
        }

        $kpis = [
            'nb_salaries' => $lignes->count(),
            'total_brut' => (float) $lignes->sum('brut'),
            'total_net' => (float) $lignes->sum('net'),
            'total_avantages_nature' => (float) $lignes->sum('total_avantages_nature'),
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
                'statut_label' => $periode->statut?->label(),
                'payable' => $periode->peutEtrePayee(),
            ] : null,
            'reprise' => $etatReprise,
            // Reprendre / valider ne sont proposés que si le serveur l'accepterait (règle 3).
            'can_reprendre' => $etatReprise['a_reprendre']
                && $user->can('update', $periode)
                && $this->reprise->peutReprendre($periode, $user),
            'can_valider_periode' => $periode !== null
                && ValidationPeriodePaieService::estValidable($periode)
                && $etatReprise['a_jour']
                && $user->can('validate', $periode)
                && $periode->lignes()->exists(),
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

        $data = $request->validate(
            PaiementSalaireService::regles() + ['date_paiement' => ['required', 'date']],
            PaiementSalaireService::messages(),
        );

        try {
            $this->paiementSalaire->payerLigne($ligne, auth()->user(), $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non enregistré : {$e->getMessage()}"]);
        }

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
        $this->assertEmployeConsultable($employe, auth()->user());

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

        // Seuls les mois validés sont payables : le bouton propose ce reste-là, pas le reste total.
        $restePayable = (float) $lignes
            ->filter(fn (PaieLigne $l) => $l->periode->peutEtrePayee())
            ->sum('reste_a_payer');
        $canPayer = auth()->user()->can('comptabilite.payer')
            && $this->paiementSalaire->peutPayerEmploye($employe, auth()->user())
            && $restePayable > 0;

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
            'reste_payable' => $restePayable,
            'can_payer' => $canPayer,
            'tresorerie' => $canPayer
                ? $this->decaissement->optionsPourEmployes(collect([$employe]), auth()->user())[$employe->id]
                : null,
        ]);
    }

    public function payerEmploye(Request $request, string $employeId): RedirectResponse
    {
        abort_unless(auth()->user()->can('comptabilite.payer'), 403);

        $orgId = auth()->user()->organization_id;
        $employe = Employe::where('organization_id', $orgId)->findOrFail($employeId);

        $data = $request->validate(PaiementSalaireService::regles(), PaiementSalaireService::messages());

        try {
            $this->paiementSalaire->payerEmploye($employe, auth()->user(), [...$data, 'date_paiement' => now()->toDateString()]);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non enregistré : {$e->getMessage()}"]);
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

        $lignes = $periode ? $this->lignesExportees($request, $periode)->get() : collect();

        $periodeLabel = $periode?->labelPeriode() ?? 'Aucune période';
        $filename = 'salaires-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($lignes, $periodeLabel) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Période', 'Salarié', 'Poste', 'Agence', 'Salaire base (GNF)', 'Brut (GNF)', 'Primes (GNF)', 'Déductions (GNF)', 'Net (GNF)', 'Avantages en nature, hors net (GNF)', 'Déjà payé (GNF)', 'Reste (GNF)', 'Statut', 'Signature'], ';');
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
                    number_format((float) $l->total_avantages_nature, 0, ',', ' '),
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
            ? $this->lignesExportees($request, $periode)
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

    /**
     * Détail transmis par les RH, par salarié, lu dans la version reprise (figée). Vide tant que rien
     * n'est repris, ou si le contenu de la version ne correspond plus à son empreinte.
     *
     * @return Collection<string, list<array<string, mixed>>>
     */
    private function elementsTransmis(PaiePeriode $periode): Collection
    {
        $transmission = $periode->transmission;
        if ($transmission === null) {
            return collect();
        }

        try {
            $lignes = $this->sourcePaie->lignes($transmission);
        } catch (ValidationException $e) {
            report($e);

            return collect();
        }

        return $lignes
            ->groupBy('employe_id')
            ->map(fn (Collection $lignes) => $lignes->map(fn (TransmissionPaieLigne $l) => [
                'libelle' => $l->libelle,
                'du' => $l->du->format(self::DATE_FORMAT),
                'au' => $l->au->format(self::DATE_FORMAT),
                'jours' => $l->jours,
                'detail_calcul' => $l->detail_calcul,
                'montant' => (float) $l->montant,
                'avantage_nature' => $l->versement === TransmissionPaieLigne::VERSEMENT_VALORISE,
            ])->values()->all());
    }

    /**
     * Lignes d'une période visibles par l'utilisateur : périmètre de consultation des agences
     * (SiteScopeService), puis filtres de l'écran. Sans agence rattachée ni vision 360°, aucune ligne.
     */
    /**
     * Mois affiché sans choix de l'utilisateur (docs/paie.md) : le mois précédent tant qu'il reste un
     * salarié à payer dans son périmètre, sinon le mois en cours. Ne crée aucune période.
     *
     * @return array{int, int}
     */
    private function moisParDefaut(User $user, string $orgId): array
    {
        $precedent = now()->startOfMonth()->subMonth();
        $periode = PaiePeriode::where('organization_id', $orgId)
            ->where('mois', $precedent->month)
            ->where('annee', $precedent->year)
            ->first();

        $resteAPayer = $periode !== null
            && ! $periode->statut->estCloture()
            && $this->lignesDuPerimetre($periode, $user, [], '')->where('reste_a_payer', '>', 0)->exists();

        return $resteAPayer ? [$precedent->month, $precedent->year] : [now()->month, now()->year];
    }

    private function lignesDuPerimetre(PaiePeriode $periode, User $user, array $filtreSiteIds, string $filtreStatut): Builder
    {
        return PaieLigne::with(['employe.site', 'contrat'])
            ->where('paie_periode_id', $periode->id)
            ->when($filtreStatut !== '', fn (Builder $q) => $q->where('statut', $filtreStatut))
            ->when($filtreSiteIds !== [], fn (Builder $q) => $q->whereHas('employe', fn (Builder $e) => $e->whereIn('site_id', $filtreSiteIds)))
            ->when(! $user->voitToutesLesAgences(), fn (Builder $q) => $q->whereHas('employe', fn (Builder $e) => $this->siteScope->applyToQuery($e, $user)));
    }

    private function lignesExportees(Request $request, PaiePeriode $periode): Builder
    {
        $user = auth()->user();

        return $this->lignesDuPerimetre($periode, $user, $this->filtreSiteIds($request, $user), (string) $request->input('statut', ''));
    }

    /** Filtre Agence de l'écran : seulement pour qui voit toutes les agences (sinon, son périmètre). */
    private function filtreSiteIds(Request $request, User $user): array
    {
        return $user->voitToutesLesAgences()
            ? array_values(array_filter((array) $request->input('site_ids', [])))
            : [];
    }

    /** @return Collection<int, Site|array{id: string, nom: string}> */
    private function sitesFiltrables(User $user, string $orgId): Collection
    {
        if ($user->voitToutesLesAgences()) {
            return Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']);
        }

        return $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom'])
            ->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])
            ->values();
    }

    private function assertEmployeConsultable(Employe $employe, User $user): void
    {
        if ($user->voitToutesLesAgences()) {
            return;
        }

        abort_unless(
            $employe->site_id !== null && $this->siteScope->accessibleSiteIds($user)->contains($employe->site_id),
            403,
            'Ce salarié ne relève pas de vos agences.',
        );
    }
}
