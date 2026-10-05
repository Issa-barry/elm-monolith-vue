<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\RemisesAgencesService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vue du Trésor principal (ADR 0016) : ce que chaque agence doit remettre à la trésorerie
 * principale, ce qui est en transit, ce qui a été reçu. Interface seulement — calculs dans
 * RemisesAgencesService, réception par la route existante `mouvements.recevoir`.
 *
 * Visibilité (`tresorerie.read`) : toutes les agences pour un administrateur ou une personne
 * affectée à la trésorerie principale — c'est sa vue de pilotage ; sinon ses seules agences.
 */
class RemisesAgencesController extends Controller
{
    public function __construct(
        private readonly RemisesAgencesService $remises,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('tresorerie.read'), 403);

        $orgId = $user->organization_id;
        [$annee, $mois, $debut, $fin] = $this->periode($request);
        $visibles = $this->agencesVisibles($user);
        $central = $this->remises->central($orgId);

        $filtreSites = array_values(array_filter((array) $request->input('site_ids', [])));
        $filtreStatut = (string) $request->input('statut', '');

        $lignes = $this->remises->lignes($orgId, $debut, $fin, $visibles);
        $totaux = $this->remises->totaux($lignes);

        $affichees = collect($lignes)
            ->when($filtreSites !== [], fn ($c) => $c->filter(fn (array $l) => in_array($l['site_id'], $filtreSites, true)))
            ->when(array_key_exists($filtreStatut, RemisesAgencesService::LIBELLES), fn ($c) => $c->where('statut', $filtreStatut))
            ->values();

        return Inertia::render('Comptabilite/Tresorerie/Remises/Index', [
            'central' => $central ? ['id' => $central->id, 'nom' => $central->nom] : null,
            'lignes' => $affichees,
            'totaux' => $totaux,
            'filters' => [
                'annee' => (string) $annee,
                'mois' => (string) $mois,
                'site_ids' => $filtreSites,
                'statut' => $filtreStatut,
            ],
            'sites' => collect($lignes)->map(fn (array $l) => ['id' => $l['site_id'], 'nom' => $l['site_nom']])->values(),
            'statut_options' => collect(RemisesAgencesService::LIBELLES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function show(Request $request, Site $site): Response
    {
        $user = $request->user();
        abort_unless($user->can('tresorerie.read'), 403);

        $orgId = $user->organization_id;
        $central = $this->remises->central($orgId);
        abort_if($site->organization_id !== $orgId || $central === null || $site->id === $central->id, 404);

        $visibles = $this->agencesVisibles($user);
        abort_if($visibles !== null && ! in_array($site->id, $visibles, true), 403);

        [$annee, $mois, $debut, $fin] = $this->periode($request);
        $detail = $this->remises->detail($orgId, $site, $debut, $fin);

        return Inertia::render('Comptabilite/Tresorerie/Remises/Show', [
            'central' => ['id' => $central->id, 'nom' => $central->nom],
            'agence' => ['id' => $site->id, 'nom' => $site->nom],
            'ligne' => $detail['ligne'],
            'remises' => $detail['remises']->map(fn (MouvementFonds $m) => [
                'id' => $m->id,
                'reference' => $m->reference,
                'nature_label' => $m->nature->label(),
                'montant' => (float) $m->montant,
                'date_envoi' => $m->date_envoi?->toDateString(),
                'date_reception' => $m->date_reception?->toDateString(),
                'support_origine' => $m->compteTresorerieOrigine?->libelle,
                'support_destination' => $m->compteTresorerieDestination?->libelle,
                'envoye_par' => $m->expediteur?->name,
                'recu_par' => $m->receptionnaire?->name,
                'statut' => $m->statut->value,
                'statut_label' => $m->statut->label(),
                // Policy vérifiée EXPLICITEMENT (permission, état, affectation au site qui reçoit) ;
                // MouvementFondsController::recevoir() la revérifie à la confirmation.
                'peut_recevoir' => $user->can('recevoir', $m),
            ])->values(),
            'supports_reception' => $this->remises->supportsDeReception($orgId)->map(fn ($s) => ['id' => $s->id, 'libelle' => $s->libelle])->values(),
            'filters' => ['annee' => (string) $annee, 'mois' => (string) $mois],
        ]);
    }

    /** @return array{0: int, 1: int, 2: Carbon, 3: Carbon} */
    private function periode(Request $request): array
    {
        $annee = (int) $request->input('annee', now()->year);
        $mois = (int) $request->input('mois', now()->month);
        if ($mois < 1 || $mois > 12 || $annee < 2000 || $annee > 2100) {
            [$annee, $mois] = [now()->year, now()->month];
        }

        $debut = Carbon::create($annee, $mois, 1)->startOfDay();

        return [$annee, $mois, $debut, $debut->copy()->endOfMonth()->endOfDay()];
    }

    /** @return list<string>|null null = toutes les agences */
    private function agencesVisibles(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        $central = $this->remises->central($user->organization_id);
        if ($central && $user->isAssignedToSite($central->id)) {
            return null;
        }

        return $this->siteScope->accessibleSiteIds($user)->values()->all();
    }
}
