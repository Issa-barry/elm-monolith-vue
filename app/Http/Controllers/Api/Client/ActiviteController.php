<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\ActiviteMineRequest;
use App\Models\CommandeVente;
use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Data\ActiviteRow;
use App\Services\Client\Data\VehiculeSummary;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Historique d'activité complet (commandes de vente + transferts logistiques,
 * TOUS statuts) pour un proprietaire — comble un vrai manque : `/livraisons/en-cours`
 * (LivraisonsEnCoursController) ne montre que le "en cours", et
 * `/v1/mobile/livraisons-transferts` (historique existant) résout par équipe
 * de livreur uniquement, **inaccessible à un proprietaire pur** (aucune
 * équipe) — cf. docs/api-espace-client-contract.md §5. Un livreur reste
 * accueilli ici aussi (même résolution véhicule/équipe que l'endpoint
 * "en cours"), mais ce n'est pas son usage principal.
 *
 * Pagination sans UNION SQL : deux modèles différents (CommandeVente,
 * TransfertLogistique) sont filtrés en base (véhicule/statut/période), comptés,
 * puis seules les `page × per_page` premières lignes de chacun sont chargées,
 * fusionnées et triées : ces lignes contiennent forcément la page demandée.
 *
 * La date filtrée et triée est celle affichée sur la ligne : validation ou
 * départ réel, à défaut création.
 */
class ActiviteController extends Controller
{
    private const DATE_VENTE = 'COALESCE(validated_at, created_at)';

    private const DATE_TRANSFERT = 'COALESCE(date_depart_reelle, created_at)';

    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ClientEarningsService $earningsService,
    ) {}

    #[Endpoint(
        description: 'Mélange deux modèles à vocabulaire de statut distinct (`StatutCommandeVente` '
            .'pour `type=vente`, `StatutTransfert` pour `type=logistique`) — aucune correspondance '
            .'n\'est inventée entre les deux. `statut` **exige `type`** (422 explicite sinon, message '
            .'"Le filtre statut nécessite de préciser type"). `type` omis = les deux mélangés, triés '
            .'par date décroissante, mais alors sans filtre `statut` possible. `periode` (période rapide '
            .'résolue par le serveur) exclut `date_debut`/`date_fin` (422 sinon). Période comptable : '
            .'`annee` + `mois` + `quinzaine` (`P1` du 1er au 15, `P2` du 16 à la fin du mois, `TOUT` ou '
            .'omise = le mois), bornes calculées par le serveur, exclut `periode` et `date_debut`/`date_fin`.',
    )]
    public function __invoke(ActiviteMineRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $this->identityResolver->resolve($user);
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

        $filters = $request->filters();

        if ($proprietaire === null && $livreur === null) {
            return $this->emptyPage($request);
        }

        $vehiculeIds = $this->earningsService
            ->vehiculesAccessibles($identity->organizationId, $proprietaire, $livreur)
            ->pluck('id');

        // Un filtre vehicule_id explicite doit primer sur le rattachement par équipe
        // (sinon un transfert historique rattaché à l'équipe du livreur mais lié à
        // un AUTRE véhicule fuiterait dans un résultat pourtant filtré sur celui-ci).
        $equipeIds = $filters['vehicule_id'] !== null ? collect() : $this->equipeIdsDuLivreur($livreur);

        if ($filters['vehicule_id'] !== null) {
            $vehiculeIds = $vehiculeIds->filter(fn ($id) => (string) $id === (string) $filters['vehicule_id'])->values();
        }

        if ($vehiculeIds->isEmpty() && $equipeIds->isEmpty()) {
            return $this->emptyPage($request);
        }

        $bornes = $request->bornes();
        $perPage = $request->perPage();
        $page = $request->page();
        $fenetre = $page * $perPage;

        $items = collect();
        $total = 0;

        if ($filters['type'] !== 'logistique' && $vehiculeIds->isNotEmpty()) {
            $ventes = $this->ventesQuery($identity->organizationId, $vehiculeIds, $filters, $bornes);
            $total += $ventes->count();
            $items = $items->concat($this->ventes($ventes, $fenetre));
        }

        if ($filters['type'] !== 'vente') {
            $transferts = $this->transfertsQuery($identity->organizationId, $vehiculeIds, $equipeIds, $filters, $bornes);
            $total += $transferts->count();
            $items = $items->concat($this->transferts($transferts, $fenetre));
        }

        $slice = $items
            ->sortBy([['dateSort', 'desc'], ['id', 'desc']])
            ->values()
            ->forPage($page, $perPage)
            ->values();

        $paginator = new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return response()->json([
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
        ]);
    }

    private function emptyPage(ActiviteMineRequest $request): JsonResponse
    {
        return response()->json([
            'data' => [],
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $request->perPage(), 'total' => 0],
            'filters' => $request->filters(),
        ]);
    }

    /** @param array{0: ?CarbonImmutable, 1: ?CarbonImmutable} $bornes */
    private function ventesQuery(?string $organizationId, Collection $vehiculeIds, array $filters, array $bornes): Builder
    {
        return CommandeVente::query()
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->whereIn('vehicule_id', $vehiculeIds)
            ->when($filters['type'] === 'vente' && $filters['statut'], fn (Builder $q) => $q->where('statut', $filters['statut']))
            ->tap(fn (Builder $q) => $this->filtrerPeriode($q, self::DATE_VENTE, $bornes));
    }

    /** @return Collection<int, ActiviteRow> */
    private function ventes(Builder $query, int $limite): Collection
    {
        return $query
            ->with(['site:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'client:id,nom,prenom', 'lignes:id,commande_vente_id,quantite_demandee'])
            ->orderByRaw(self::DATE_VENTE.' DESC')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(function (CommandeVente $c) {
                $client = $c->client;
                $date = $c->validated_at ?? $c->created_at;

                return new ActiviteRow(
                    id: $c->id,
                    type: 'vente',
                    reference: $c->reference ?? '—',
                    statut: $c->statut?->value,
                    statutLabel: $c->statut_label,
                    siteSource: $c->site?->nom ?? '—',
                    siteDestination: $client?->nom_complet ?? 'Vente directe',
                    vehicule: $c->vehicule ? new VehiculeSummary(
                        id: $c->vehicule->id,
                        nomVehicule: $c->vehicule->nom_vehicule,
                        immatriculation: $c->vehicule->immatriculation,
                    ) : null,
                    date: $date?->toDateString(),
                    dateSort: $date?->timestamp ?? 0,
                    nbPacks: (int) $c->lignes->sum('quantite_demandee'),
                );
            });
    }

    /** @param array{0: ?CarbonImmutable, 1: ?CarbonImmutable} $bornes */
    private function transfertsQuery(?string $organizationId, Collection $vehiculeIds, Collection $equipeIds, array $filters, array $bornes): Builder
    {
        return TransfertLogistique::query()
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->where(fn (Builder $q) => $q
                ->when($vehiculeIds->isNotEmpty(), fn (Builder $q2) => $q2->orWhereIn('vehicule_id', $vehiculeIds))
                ->when($equipeIds->isNotEmpty(), fn (Builder $q2) => $q2->orWhereIn('equipe_livraison_id', $equipeIds))
            )
            ->when($filters['type'] === 'logistique' && $filters['statut'], fn (Builder $q) => $q->where('statut', $filters['statut']))
            ->tap(fn (Builder $q) => $this->filtrerPeriode($q, self::DATE_TRANSFERT, $bornes));
    }

    /** @return Collection<int, ActiviteRow> */
    private function transferts(Builder $query, int $limite): Collection
    {
        return $query
            ->with(['siteSource:id,nom', 'siteDestination:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'lignes'])
            ->orderByRaw(self::DATE_TRANSFERT.' DESC')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(function (TransfertLogistique $t) {
                $date = $t->date_depart_reelle ?? $t->created_at;

                return new ActiviteRow(
                    id: $t->id,
                    type: 'logistique',
                    reference: $t->reference,
                    statut: $t->statut?->value,
                    statutLabel: $t->statut_label,
                    siteSource: $t->siteSource?->nom ?? '—',
                    siteDestination: $t->siteDestination?->nom ?? '—',
                    vehicule: $t->vehicule ? new VehiculeSummary(
                        id: $t->vehicule->id,
                        nomVehicule: $t->vehicule->nom_vehicule,
                        immatriculation: $t->vehicule->immatriculation,
                    ) : null,
                    date: $date?->toDateString(),
                    dateSort: $date?->timestamp ?? 0,
                    nbPacks: (int) $t->lignes->sum('quantite_chargee'),
                );
            });
    }

    /** @param array{0: ?CarbonImmutable, 1: ?CarbonImmutable} $bornes */
    private function filtrerPeriode(Builder $query, string $colonneDate, array $bornes): void
    {
        [$debut, $fin] = $bornes;

        $query
            ->when($debut, fn (Builder $q) => $q->whereRaw("{$colonneDate} >= ?", [$debut->toDateTimeString()]))
            ->when($fin, fn (Builder $q) => $q->whereRaw("{$colonneDate} <= ?", [$fin->toDateTimeString()]));
    }

    /** @return Collection<int, string> */
    private function equipeIdsDuLivreur(?Livreur $livreur): Collection
    {
        if ($livreur === null) {
            return collect();
        }

        return $livreur->equipes()->pluck('equipes_livraison.id');
    }
}
