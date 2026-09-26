<?php

namespace App\Services\Rapports;

use App\Models\Site;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Support\Rapports\RapportPerimetre;
use App\Support\Vehicules\SituationPeriode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seul point qui transforme une requête en périmètre de rapport. Les paramètres envoyés par le
 * navigateur (site_ids[], agent_id) ne font jamais que RESTREINDRE ce que les droits autorisent :
 *
 * - « Ma situation » (`rapports.read_own`) : agent = l'utilisateur connecté, quoi qu'il envoie ;
 *   toutes les agences de l'organisation (ce sont ses propres ventes et encaissements).
 * - Rapport d'activité (`rapports.read`) : agences accessibles selon SiteScopeService (le même
 *   mécanisme que la trésorerie : toute l'organisation pour un administrateur, ses agences
 *   `user_sites` sinon), puis agent choisi parmi ceux de ces agences.
 */
class RapportPerimetreResolver
{
    /** Périodes proposées par le rapport (décision du 26/09/2026). */
    public const PERIODES = ['aujourd_hui', 'hier', 'cette_semaine', 'ce_mois', SituationPeriode::PERSONNALISEE];

    public const PARAMETRE_PERIODE = 'periode';

    public function __construct(private readonly SiteScopeService $siteScope) {}

    public function pourMaSituation(User $user, Request $request): RapportPerimetre
    {
        abort_unless($user->can('rapports.read_own'), 403);

        return new RapportPerimetre(
            organizationId: $user->organization_id,
            siteIds: null,
            agentId: $user->id,
            periode: $this->periode($request),
            maSituation: true,
        );
    }

    public function pourRapport(User $user, Request $request): RapportPerimetre
    {
        abort_unless($user->can('rapports.read'), 403);

        $autorises = $this->sitesAutorises($user);
        $demandes = array_values(array_filter(array_map('strval', (array) $request->input('site_ids', []))));

        $siteIds = $autorises;
        if ($demandes !== []) {
            $siteIds = array_values(array_intersect($demandes, $autorises ?? $this->sitesOrganisation($user)));
        }

        $agentId = (string) $request->input('agent_id', '');
        if ($agentId !== '' && ! $this->agentsPour($user->organization_id, $siteIds)->contains('id', $agentId)) {
            $agentId = '';
        }

        return new RapportPerimetre(
            organizationId: $user->organization_id,
            siteIds: $siteIds,
            agentId: $agentId !== '' ? $agentId : null,
            periode: $this->periode($request),
            maSituation: false,
        );
    }

    /**
     * Agences proposées au filtre Agence : celles que l'utilisateur peut voir.
     *
     * @return Collection<int, array{id: string, nom: string}>
     */
    public function sitesProposes(User $user): Collection
    {
        $autorises = $this->sitesAutorises($user);

        return Site::query()
            ->where('organization_id', $user->organization_id)
            ->when($autorises !== null, fn ($q) => $q->whereIn('id', $autorises))
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn (Site $s) => ['id' => $s->id, 'nom' => $s->nom]);
    }

    /**
     * Agents d'un ensemble d'agences : les utilisateurs rattachés à ces agences, plus ceux qui y
     * ont créé une vente ou enregistré un encaissement (un administrateur non rattaché vend aussi).
     *
     * @param  list<string>|null  $siteIds
     * @return Collection<int, array{id: string, nom: string}>
     */
    public function agentsPour(string $organizationId, ?array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }

        $rattaches = DB::table('user_sites')
            ->join('sites', 'sites.id', '=', 'user_sites.site_id')
            ->where('sites.organization_id', $organizationId)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('user_sites.site_id', $siteIds))
            ->pluck('user_sites.user_id');

        $vendeurs = DB::table('commandes_ventes')
            ->where('organization_id', $organizationId)
            ->whereNotNull('created_by')
            ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds))
            ->distinct()
            ->pluck('created_by');

        $encaisseurs = DB::table('encaissements_ventes as ev')
            ->join('factures_ventes as fv', 'fv.id', '=', 'ev.facture_vente_id')
            ->where('fv.organization_id', $organizationId)
            ->whereNotNull('ev.created_by')
            ->when($siteIds !== null, fn ($q) => $q->whereIn('fv.site_id', $siteIds))
            ->distinct()
            ->pluck('ev.created_by');

        $ids = $rattaches->merge($vendeurs)->merge($encaisseurs)->unique()->values();

        return User::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->with('personne')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'nom' => $u->name !== '' ? $u->name : ($u->matricule ?: 'Utilisateur sans nom')])
            ->sortBy('nom', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function periode(Request $request): SituationPeriode
    {
        return SituationPeriode::depuisRequete($request, null, self::PARAMETRE_PERIODE, 'aujourd_hui');
    }

    /**
     * @return list<string>|null null = toute l'organisation
     */
    private function sitesAutorises(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        return $this->siteScope->accessibleSiteIds($user)->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * @return list<string>
     */
    private function sitesOrganisation(User $user): array
    {
        return Site::where('organization_id', $user->organization_id)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
