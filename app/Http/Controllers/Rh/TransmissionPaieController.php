<?php

namespace App\Http\Controllers\Rh;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\StatutTransmissionPaie;
use App\Http\Controllers\Comptabilite\HistoriqueActionsController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\User;
use App\Services\Rh\Paie\TransmissionPaieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran RH « Transmission de paie » (docs/transmission-paie.md, ADR 0040). Les montants ne sont
 * montrés que pour les salariés des agences consultables ; les totaux de l'organisation, seulement
 * à qui voit toutes les agences.
 */
class TransmissionPaieController extends Controller
{
    public function __construct(private readonly TransmissionPaieService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TransmissionPaie::class);

        /** @var User $user */
        $user = $request->user();
        $annee = (int) $request->input('annee', now()->year);
        $toutes = $user->voitToutesLesAgences();

        $transmissions = TransmissionPaie::where('organization_id', $user->organization_id)
            ->where('annee', $annee)
            ->with(['preparateur.personne', 'valideur.personne', 'transmetteur.personne'])
            ->orderByDesc('mois')->orderByDesc('version')
            ->get()
            ->map(fn (TransmissionPaie $t) => [
                ...$this->resume($t),
                // Totaux de l'organisation : seulement pour qui voit toutes les agences.
                'total_verse' => $toutes ? (float) $t->total_verse : null,
                'total_valorise' => $toutes ? (float) $t->total_valorise : null,
            ]);

        $annees = TransmissionPaie::where('organization_id', $user->organization_id)
            ->distinct()->orderByDesc('annee')->pluck('annee')
            ->push(now()->year)->unique()->sortDesc()->values();

        return Inertia::render('TransmissionsPaie/Index', [
            'transmissions' => $transmissions,
            'filters' => ['annee' => (string) $annee],
            'annees' => $annees->map(fn ($a) => ['value' => (string) $a, 'label' => (string) $a])->all(),
            'mois_propose' => ['annee' => now()->year, 'mois' => now()->month],
            'peut_agir_toutes_agences' => $user->agitSurToutesLesAgences(),
        ]);
    }

    public function show(Request $request, TransmissionPaie $transmission): Response
    {
        $this->authorize('view', $transmission);
        abort_if($transmission->organization_id !== $request->user()->organization_id, 403);

        /** @var User $user */
        $user = $request->user();
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $texte = trim((string) $request->input('salarie', ''));
        $visible = $this->filtreVisibilite($user);

        $lignes = TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)
            ->orderBy('nom_salarie')->orderBy('ordre')
            ->get()
            ->filter(fn (TransmissionPaieLigne $l) => $visible($l->site_id))
            ->filter(fn (TransmissionPaieLigne $l) => $siteIds === [] || in_array($l->site_id, $siteIds, true))
            ->filter(fn (TransmissionPaieLigne $l) => $texte === '' || str_contains(mb_strtolower($l->nom_salarie.' '.$l->matricule), mb_strtolower($texte)))
            ->values();

        $anomalies = collect($transmission->anomaliesListe())
            ->filter(fn (array $a) => $visible($a['site_id'] ?? null))
            ->values();

        $versions = TransmissionPaie::where('organization_id', $transmission->organization_id)
            ->where('annee', $transmission->annee)->where('mois', $transmission->mois)
            ->with(['preparateur.personne', 'valideur.personne', 'transmetteur.personne'])
            ->orderByDesc('version')
            ->get();

        // Écarts avec la version figée précédente (ce qui a changé dans cette version).
        $ecartsPrecedente = [];
        if ($transmission->version_precedente_id !== null) {
            $precedente = TransmissionPaieLigne::where('transmission_paie_id', $transmission->version_precedente_id)->get();
            $ecartsPrecedente = $this->visibles($this->service->differences($precedente, TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->get()), $visible);
        }

        // Changements intervenus depuis cette version figée (la plus récente du mois) : une nouvelle version les reprendrait.
        $derniereFigee = $versions->first(fn (TransmissionPaie $t) => $t->statut->estFigee());
        $changements = $transmission->statut->estFigee() && $derniereFigee?->id === $transmission->id
            ? $this->visibles($this->service->changementsDepuis($transmission), $visible)
            : [];

        return Inertia::render('TransmissionsPaie/Show', [
            'transmission' => [
                ...$this->resume($transmission->loadMissing(['preparateur.personne', 'valideur.personne', 'transmetteur.personne'])),
                'version_precedente' => $versions->firstWhere('id', $transmission->version_precedente_id)?->version,
                'est_figee' => $transmission->statut->estFigee(),
                'integre' => $transmission->statut->estFigee() ? $this->service->estIntegre($transmission) : null,
                'empreinte' => $transmission->empreinte,
                'organisation_complete' => $user->voitToutesLesAgences(),
                'total_verse_organisation' => $user->voitToutesLesAgences() ? (float) $transmission->total_verse : null,
                'total_valorise_organisation' => $user->voitToutesLesAgences() ? (float) $transmission->total_valorise : null,
            ],
            'salaries' => $this->parSalarie($lignes),
            'recapitulatif' => $this->recapitulatif($lignes),
            'totaux_visibles' => [
                'verse' => round((float) $lignes->where('versement', TransmissionPaieLigne::VERSEMENT_VERSE)->sum('montant'), 2),
                'valorise' => round((float) $lignes->where('versement', TransmissionPaieLigne::VERSEMENT_VALORISE)->sum('montant'), 2),
                'nb_salaries' => $lignes->pluck('employe_id')->unique()->count(),
            ],
            'anomalies' => $anomalies,
            'versions' => $versions->map(fn (TransmissionPaie $t) => $this->resume($t))->values(),
            'ecarts_precedente' => $ecartsPrecedente,
            'changements_depuis' => $changements,
            'filters' => ['site_ids' => $siteIds, 'salarie' => $texte],
            'sites' => $this->sitesFiltre($user),
            'peut_agir_toutes_agences' => $user->agitSurToutesLesAgences(),
        ]);
    }

    public function preparer(Request $request): RedirectResponse
    {
        $this->authorize('preparer', TransmissionPaie::class);

        $data = $request->validate([
            'annee' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mois' => ['required', 'integer', 'min:1', 'max:12'],
            'motif' => ['nullable', 'string', 'max:1000'],
        ], [
            'annee.required' => 'L\'année est obligatoire.',
            'mois.required' => 'Le mois est obligatoire.',
        ]);

        $transmission = $this->service->preparer($request->user(), (int) $data['annee'], (int) $data['mois'], $data['motif'] ?? null);

        return redirect()->route('transmissions-paie.show', $transmission)
            ->with('success', "Version {$transmission->version} de {$transmission->libelleMois()} préparée : vérifiez les montants et les anomalies.");
    }

    public function recalculer(Request $request, TransmissionPaie $transmission): RedirectResponse
    {
        $this->authorize('preparer', TransmissionPaie::class);
        $this->service->recalculer($transmission, $request->user());

        return back()->with('success', 'Brouillon recalculé à partir des données RH actuelles.');
    }

    public function abandonner(Request $request, TransmissionPaie $transmission): RedirectResponse
    {
        $this->authorize('preparer', TransmissionPaie::class);
        $this->service->abandonner($transmission, $request->user());

        return back()->with('success', 'Brouillon abandonné.');
    }

    public function valider(Request $request, TransmissionPaie $transmission): RedirectResponse
    {
        $this->authorize('valider', $transmission);
        $transmission = $this->service->valider($transmission, $request->user());

        return back()->with('success', "Version {$transmission->version} validée : elle est figée.");
    }

    public function transmettre(Request $request, TransmissionPaie $transmission): RedirectResponse
    {
        $this->authorize('transmettre', $transmission);
        $transmission = $this->service->transmettre($transmission, $request->user());

        return back()->with('success', "Version {$transmission->version} transmise à la Comptabilité.");
    }

    public function historique(Request $request, TransmissionPaie $transmission): JsonResponse
    {
        $this->authorize('view', $transmission);
        abort_if($transmission->organization_id !== $request->user()->organization_id, 403);

        $logs = AuditLog::where('organization_id', $transmission->organization_id)
            ->where('auditable_type', $transmission->getMorphClass())
            ->where('auditable_id', $transmission->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => HistoriqueActionsController::transformLog($log));

        return response()->json($logs);
    }

    /** @return array<string, mixed> */
    private function resume(TransmissionPaie $t): array
    {
        $anomalies = collect($t->anomaliesListe());

        return [
            'id' => $t->id,
            'annee' => $t->annee,
            'mois' => $t->mois,
            'libelle_mois' => ucfirst($t->libelleMois()),
            'version' => $t->version,
            'statut' => $t->statut->value,
            'statut_label' => $t->statut->label(),
            'motif' => $t->motif,
            'nb_salaries' => $t->nb_salaries,
            'nb_bloquantes' => $anomalies->where('niveau', 'bloquante')->count(),
            'nb_avertissements' => $anomalies->where('niveau', 'avertissement')->count(),
            'preparee_par' => $t->preparateur?->name,
            'preparee_le' => $t->preparee_le?->format('d/m/Y H:i'),
            'validee_par' => $t->valideur?->name,
            'validee_le' => $t->validee_le?->format('d/m/Y H:i'),
            'transmise_par' => $t->transmetteur?->name,
            'transmise_le' => $t->transmise_le?->format('d/m/Y H:i'),
            'prise_en_charge_le' => $t->prise_en_charge_le?->format('d/m/Y H:i'),
            'prise_en_charge_reference' => $t->prise_en_charge_reference,
            'statut_comptabilite' => $t->statut === StatutTransmissionPaie::TRANSMISE
                ? ($t->prise_en_charge_le ? 'Reprise par la Comptabilité' : 'En attente de reprise par la Comptabilité')
                : null,
        ];
    }

    /** @return callable(?string): bool */
    private function filtreVisibilite(User $user): callable
    {
        if ($user->voitToutesLesAgences()) {
            return fn (?string $siteId) => true;
        }
        $sites = $user->sites()->pluck('sites.id')->all();

        return fn (?string $siteId) => $siteId !== null && in_array($siteId, $sites, true);
    }

    /**
     * @param  list<array<string, mixed>>  $ecarts
     * @param  callable(?string): bool  $visible
     * @return list<array<string, mixed>>
     */
    private function visibles(array $ecarts, callable $visible): array
    {
        return array_values(array_filter($ecarts, fn (array $e) => $visible($e['site_id'])));
    }

    /**
     * @param  Collection<int, TransmissionPaieLigne>  $lignes
     * @return list<array<string, mixed>>
     */
    private function parSalarie(Collection $lignes): array
    {
        return $lignes->groupBy('employe_id')
            ->map(function (Collection $groupe) {
                $premiere = $groupe->first();

                return [
                    'employe_id' => $premiere->employe_id,
                    'salarie' => $premiere->nom_salarie,
                    'matricule' => $premiere->matricule,
                    'site' => $premiere->site_nom,
                    'fonction' => $premiere->fonction_libelle,
                    'total_verse' => round((float) $groupe->where('versement', TransmissionPaieLigne::VERSEMENT_VERSE)->sum('montant'), 2),
                    'total_valorise' => round((float) $groupe->where('versement', TransmissionPaieLigne::VERSEMENT_VALORISE)->sum('montant'), 2),
                    'lignes' => $groupe->map(fn (TransmissionPaieLigne $l) => [
                        'id' => $l->id,
                        'origine' => $l->origine,
                        'code' => $l->code,
                        'libelle' => $l->libelle,
                        'categorie_label' => $l->categorie ? CategorieTypeRemuneration::from($l->categorie)->label() : 'Salaire',
                        'versement' => $l->versement,
                        'du' => $l->du->format('d/m/Y'),
                        'au' => $l->au->format('d/m/Y'),
                        'jours' => $l->jours,
                        'jours_mois' => $l->jours_mois,
                        'montant_reference' => $l->montant_reference !== null ? (float) $l->montant_reference : null,
                        'base_calcul' => $l->base_calcul !== null ? (float) $l->base_calcul : null,
                        'taux' => $l->taux !== null ? (float) $l->taux : null,
                        'montant' => (float) $l->montant,
                        'detail_calcul' => $l->detail_calcul,
                        'source' => $l->origine === TransmissionPaieLigne::ORIGINE_CONTRAT ? 'Contrat' : ($l->saisie_id ? 'Montant du mois' : 'Affectation'),
                    ])->values()->all(),
                ];
            })
            ->sortBy('salarie')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TransmissionPaieLigne>  $lignes
     * @return list<array<string, mixed>>
     */
    private function recapitulatif(Collection $lignes): array
    {
        return $lignes->groupBy(fn (TransmissionPaieLigne $l) => $l->origine === TransmissionPaieLigne::ORIGINE_CONTRAT ? 'SALAIRE_BASE' : (string) $l->code)
            ->map(function (Collection $groupe, string $cle) {
                $premiere = $groupe->first();

                return [
                    'cle' => $cle,
                    'element' => $cle === 'SALAIRE_BASE' ? 'Salaire de base (contrats)' : $premiere->libelle,
                    'code' => $premiere->code,
                    'categorie_label' => $premiere->categorie ? CategorieTypeRemuneration::from($premiere->categorie)->label() : 'Salaire',
                    'versement' => $premiere->versement,
                    'nb_salaries' => $groupe->pluck('employe_id')->unique()->count(),
                    'total' => round((float) $groupe->sum('montant'), 2),
                ];
            })
            ->sortBy(fn (array $r) => ($r['cle'] === 'SALAIRE_BASE' ? '0' : '1').$r['element'])
            ->values()
            ->all();
    }

    /** @return list<array{id: string, nom: string}> */
    private function sitesFiltre(User $user): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $user->organization_id)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all();
    }
}
