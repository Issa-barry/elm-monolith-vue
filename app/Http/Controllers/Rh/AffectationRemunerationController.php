<?php

namespace App\Http\Controllers\Rh;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutEmploye;
use App\Enums\StatutTypeRemuneration;
use App\Http\Controllers\Comptabilite\HistoriqueActionsController;
use App\Http\Controllers\Controller;
use App\Http\Requests\AffectationRemunerationRequest;
use App\Models\AffectationRemuneration;
use App\Models\AuditLog;
use App\Models\Employe;
use App\Models\Site;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\Rh\AffectationRemunerationService;
use App\Services\Rh\Paie\CalendrierPaieService;
use App\Services\SiteScopeService;
use App\Support\Rh\AffectationRemunerationPresenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran RH « Primes des salariés » (docs/primes-avantages.md, ADR 0039). Lecture limitée aux
 * agences consultables ; chaque écriture passe par AffectationRemunerationService, qui revérifie
 * le droit de validation, l'organisation et l'agence du salarié.
 */
class AffectationRemunerationController extends Controller
{
    public function __construct(private readonly AffectationRemunerationService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AffectationRemuneration::class);

        /** @var User $user */
        $user = $request->user();
        $orgId = $user->organization_id;
        $filtres = $this->filtres($request, $user);

        $query = AffectationRemuneration::where('organization_id', $orgId)
            ->whereHas('employe', fn (Builder $q) => app(SiteScopeService::class)->applyToQuery($q, $user))
            ->with(AffectationRemunerationPresenter::RELATIONS);

        $this->appliquerFiltres($query, $filtres);

        $affectations = $query->get()
            ->sortBy([
                fn (AffectationRemuneration $a, AffectationRemuneration $b) => strcmp($a->employe?->nom_complet ?? '', $b->employe?->nom_complet ?? ''),
                fn (AffectationRemuneration $a, AffectationRemuneration $b) => $b->date_debut <=> $a->date_debut,
            ])
            ->values();

        $aValider = AffectationRemuneration::where('organization_id', $orgId)
            ->whereHas('employe', fn (Builder $q) => app(SiteScopeService::class)->applyToQuery($q, $user))
            ->where(fn (Builder $q) => $q->where('statut', StatutAffectationRemuneration::A_VALIDER->value)->orWhereNotNull('date_fin_demandee'))
            ->count();

        $premierJour = app(CalendrierPaieService::class)->premierJourModifiable($orgId);

        return Inertia::render('AffectationsRemuneration/Index', [
            'affectations' => (new AffectationRemunerationPresenter($user))->liste($affectations),
            'filters' => $filtres,
            'nb_a_valider' => $aValider,
            'premier_jour_modifiable' => $premierJour?->toDateString(),
            'sites' => $this->sitesFiltre($user, $orgId),
            'employes' => $this->employesOptions($user, $orgId),
            'types' => $this->typesOptions($orgId),
            'categories' => CategorieTypeRemuneration::options(),
            'etats' => StatutAffectationRemuneration::optionsEtat(),
        ]);
    }

    public function store(AffectationRemunerationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $affectation = $this->service->affecter($data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $affectation->statut === StatutAffectationRemuneration::VALIDEE
            ? 'Affectation enregistrée et validée.'
            : 'Affectation enregistrée : elle attend sa validation.');
    }

    public function update(AffectationRemunerationRequest $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $data = $request->validated();
        $affectation = $this->service->modifier($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $affectation->statut === StatutAffectationRemuneration::VALIDEE
            ? 'Proposition modifiée et validée.'
            : 'Proposition modifiée.');
    }

    public function changerMontant(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('view', $affectation);
        $this->authorize('create', AffectationRemuneration::class);

        $data = $request->validate([
            'date_effet' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_effet'],
            'montant' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'taux' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'valider' => ['boolean'],
        ], [
            'date_effet.required' => 'La date d\'effet du nouveau montant est obligatoire.',
            'date_fin.after_or_equal' => 'La fin d\'effet doit être postérieure ou égale à la date du changement.',
            'montant.gt' => 'Le montant doit être supérieur à 0.',
            'taux.max' => 'Le taux ne peut pas dépasser 100 %.',
        ]);

        $successeur = $this->service->changerMontant($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $successeur->statut === StatutAffectationRemuneration::VALIDEE
            ? 'Nouveau montant validé : il s\'applique à partir du '.$successeur->date_debut->format('d/m/Y').'.'
            : 'Changement de montant enregistré : il attend sa validation.');
    }

    public function demanderFin(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('update', $affectation);

        $data = $request->validate([
            'date_fin' => ['required', 'date_format:Y-m-d'],
            'motif' => ['required', 'string', 'max:255'],
            'valider' => ['boolean'],
        ], [
            'date_fin.required' => 'La date de fin d\'effet est obligatoire.',
            'motif.required' => 'Le motif est obligatoire.',
        ]);

        $affectation = $this->service->demanderFin($affectation, $data, $request->user(), (bool) ($data['valider'] ?? false));

        return back()->with('success', $affectation->date_fin_demandee === null
            ? 'Fin d\'effet enregistrée au '.$affectation->date_fin->format('d/m/Y').'.'
            : 'Fin d\'effet demandée : elle attend sa validation.');
    }

    public function retirerDemandeFin(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('update', $affectation);
        $this->service->retirerDemandeFin($affectation, $request->user());

        return back()->with('success', 'Demande de fin d\'effet retirée.');
    }

    public function valider(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('valider', $affectation);
        $this->service->valider($affectation, $request->user());

        return back()->with('success', 'Validation enregistrée.');
    }

    public function annuler(Request $request, AffectationRemuneration $affectation): RedirectResponse
    {
        $this->authorize('delete', $affectation);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:255']]);
        $this->service->annuler($affectation, $data['motif'] ?? null, $request->user());

        return back()->with('success', 'Affectation annulée. Elle reste visible dans l\'historique.');
    }

    public function historique(Request $request, AffectationRemuneration $affectation): JsonResponse
    {
        $this->authorize('view', $affectation);
        // Contrôles explicites : le Gate::before du super administrateur ne vérifie ni l'organisation ni l'agence.
        abort_unless(AffectationRemunerationService::peutConsulter($affectation->employe, $request->user()), 403);

        $logs = AuditLog::where('organization_id', $affectation->organization_id)
            ->where('auditable_type', $affectation->getMorphClass())
            ->where('auditable_id', $affectation->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => HistoriqueActionsController::transformLog($log));

        return response()->json($logs);
    }

    /** @return array<string, mixed> */
    private function filtres(Request $request, User $user): array
    {
        return [
            // Filtre Agence : seulement parmi les agences consultables (le périmètre s'applique de toute façon).
            'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
            'etat' => (string) $request->input('etat', ''),
            'employe_id' => (string) $request->input('employe_id', ''),
            'type_remuneration_id' => (string) $request->input('type_remuneration_id', ''),
            'categorie' => (string) $request->input('categorie', ''),
            'periode_du' => $this->dateOuVide($request->input('periode_du')),
            'periode_au' => $this->dateOuVide($request->input('periode_au')),
        ];
    }

    /** @param  array<string, mixed>  $filtres */
    private function appliquerFiltres(Builder $query, array $filtres): void
    {
        if ($filtres['site_ids'] !== []) {
            $query->whereHas('employe', fn (Builder $q) => $q->whereIn('site_id', $filtres['site_ids']));
        }
        if ($filtres['employe_id'] !== '') {
            $query->where('employe_id', $filtres['employe_id']);
        }
        if ($filtres['type_remuneration_id'] !== '') {
            $query->where('type_remuneration_id', $filtres['type_remuneration_id']);
        }
        if ($filtres['categorie'] !== '') {
            $query->whereHas('type', fn (Builder $q) => $q->where('categorie', $filtres['categorie']));
        }
        if ($filtres['periode_du'] !== '' || $filtres['periode_au'] !== '') {
            $du = $filtres['periode_du'] !== '' ? Carbon::parse($filtres['periode_du']) : Carbon::create(1900, 1, 1);
            $au = $filtres['periode_au'] !== '' ? Carbon::parse($filtres['periode_au']) : Carbon::create(9999, 12, 31);
            $query->enVigueurEntre($du, $au);
        }

        $aujourdhui = today()->toDateString();
        match ($filtres['etat']) {
            'a_valider' => $query->where(fn (Builder $q) => $q->where('statut', StatutAffectationRemuneration::A_VALIDER->value)->orWhereNotNull('date_fin_demandee')),
            'annulee' => $query->where('statut', StatutAffectationRemuneration::ANNULEE->value),
            'a_venir' => $query->where('statut', StatutAffectationRemuneration::VALIDEE->value)->whereDate('date_debut', '>', $aujourdhui),
            'en_cours' => $query->where('statut', StatutAffectationRemuneration::VALIDEE->value)->whereDate('date_debut', '<=', $aujourdhui)
                ->where(fn (Builder $q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $aujourdhui)),
            'terminee' => $query->where('statut', StatutAffectationRemuneration::VALIDEE->value)->whereDate('date_fin', '<', $aujourdhui),
            default => null,
        };
    }

    private function dateOuVide(mixed $valeur): string
    {
        return is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) ? $valeur : '';
    }

    /** @return list<array{id: string, nom: string}> */
    private function sitesFiltre(User $user, string $orgId): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom])->values()->all();
    }

    /**
     * Salariés consultables (filtre) ; `attribuable` = l'utilisateur peut leur attribuer une prime
     * (agence d'écriture, salarié non sorti).
     *
     * @return list<array<string, mixed>>
     */
    private function employesOptions(User $user, string $orgId): array
    {
        $query = Employe::where('organization_id', $orgId)->with(['personne', 'site:id,nom']);
        app(SiteScopeService::class)->applyToQuery($query, $user);
        $toutes = $user->agitSurToutesLesAgences();
        $sites = $toutes ? [] : $user->sites()->pluck('sites.id')->all();

        return $query->get()
            ->sortBy('nom_complet')
            ->map(fn (Employe $e) => [
                'value' => $e->id,
                'label' => trim($e->nom_complet.($e->matricule ? " ({$e->matricule})" : '')),
                'site' => $e->site?->nom,
                'date_embauche' => $e->date_embauche?->toDateString(),
                'date_sortie' => $e->date_sortie?->toDateString(),
                'attribuable' => ($toutes || ($e->site_id !== null && in_array($e->site_id, $sites, true)))
                    && ! ($e->statut === StatutEmploye::SORTI && $e->date_sortie === null),
            ])
            ->values()
            ->all();
    }

    /**
     * Tous les types de l'organisation (filtre) ; `attribuable` = actif (la période d'application
     * est revérifiée par le serveur à la date d'effet).
     *
     * @return list<array<string, mixed>>
     */
    private function typesOptions(string $orgId): array
    {
        return TypeRemuneration::where('organization_id', $orgId)
            ->orderBy('libelle')
            ->get()
            ->map(fn (TypeRemuneration $t) => [
                'value' => $t->id,
                'label' => $t->libelle,
                'code' => $t->code,
                'categorie' => $t->categorie->value,
                'categorie_label' => $t->categorie->label(),
                'valorise' => $t->categorie->estValorise(),
                'mode_calcul' => $t->mode_calcul->value,
                'periodicite' => $t->periodicite->value,
                'nature_montant' => $t->nature_montant->value,
                'cumul_autorise' => $t->cumul_autorise,
                'montant_defaut' => $t->montant_defaut !== null ? (float) $t->montant_defaut : null,
                'taux_defaut' => $t->taux_defaut !== null ? (float) $t->taux_defaut : null,
                'date_debut_application' => $t->date_debut_application?->toDateString(),
                'date_fin_application' => $t->date_fin_application?->toDateString(),
                'statut' => $t->statut->value,
                'attribuable' => $t->statut === StatutTypeRemuneration::ACTIF,
            ])
            ->values()
            ->all();
    }
}
