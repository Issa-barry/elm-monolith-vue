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
 * Pagination calculée en mémoire (pas de UNION SQL) : deux modèles différents
 * (CommandeVente, TransfertLogistique) sont fusionnés puis triés par date —
 * chaque sous-requête est filtrée en base (véhicule/statut/période) avant
 * fusion, donc le volume réellement chargé reste borné à l'activité d'UN
 * propriétaire, jamais à toute la table.
 */
class ActiviteController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ClientEarningsService $earningsService,
    ) {}

    #[Endpoint(
        description: 'Mélange deux modèles à vocabulaire de statut distinct (`StatutCommandeVente` '
            .'pour `type=vente`, `StatutTransfert` pour `type=logistique`) — aucune correspondance '
            .'n\'est inventée entre les deux. `statut` **exige `type`** (422 explicite sinon, message '
            .'"Le filtre statut nécessite de préciser type"). `type` omis = les deux mélangés, triés '
            .'par date décroissante, mais alors sans filtre `statut` possible.',
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

        $items = collect();

        if ($filters['type'] !== 'logistique') {
            $items = $items->concat($this->ventes($identity->organizationId, $vehiculeIds, $filters));
        }

        if ($filters['type'] !== 'vente') {
            $items = $items->concat($this->transferts($identity->organizationId, $vehiculeIds, $equipeIds, $filters));
        }

        $items = $items->sortByDesc('date_sort')->values();

        $perPage = $request->perPage();
        $page = $request->page();
        $slice = $items->forPage($page, $perPage)->map(function (array $row) {
            unset($row['date_sort']);

            return $row;
        })->values();

        $paginator = new LengthAwarePaginator($slice, $items->count(), $perPage, $page, [
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

    /** @return Collection<int, array<string, mixed>> */
    private function ventes(?string $organizationId, Collection $vehiculeIds, array $filters): Collection
    {
        if ($vehiculeIds->isEmpty()) {
            return collect();
        }

        return CommandeVente::query()
            ->with(['site:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'client:id,nom,prenom', 'lignes:id,commande_vente_id,quantite_demandee'])
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->whereIn('vehicule_id', $vehiculeIds)
            ->when($filters['type'] === 'vente' && $filters['statut'], fn (Builder $q) => $q->where('statut', $filters['statut']))
            ->when($filters['date_debut'], fn (Builder $q) => $q->whereDate('validated_at', '>=', $filters['date_debut']))
            ->when($filters['date_fin'], fn (Builder $q) => $q->whereDate('validated_at', '<=', $filters['date_fin']))
            ->orderByDesc('validated_at')
            ->limit(200)
            ->get()
            ->map(function (CommandeVente $c) {
                $client = $c->client;
                $date = $c->validated_at ?? $c->created_at;

                return [
                    'id' => $c->id,
                    'type' => 'vente',
                    'reference' => $c->reference ?? '—',
                    'statut' => $c->statut?->value,
                    'statut_label' => $c->statut_label,
                    'site_source' => $c->site?->nom ?? '—',
                    'site_destination' => $client?->nom_complet ?? 'Vente directe',
                    'vehicule' => $c->vehicule ? [
                        'id' => $c->vehicule->id,
                        'nom_vehicule' => $c->vehicule->nom_vehicule,
                        'immatriculation' => $c->vehicule->immatriculation,
                    ] : null,
                    'date' => $date?->toDateString(),
                    'date_sort' => $date?->timestamp ?? 0,
                    'nb_packs' => (int) $c->lignes->sum('quantite_demandee'),
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function transferts(?string $organizationId, Collection $vehiculeIds, Collection $equipeIds, array $filters): Collection
    {
        if ($vehiculeIds->isEmpty() && $equipeIds->isEmpty()) {
            return collect();
        }

        return TransfertLogistique::query()
            ->with(['siteSource:id,nom', 'siteDestination:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'lignes'])
            ->when($organizationId, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->where(fn (Builder $q) => $q
                ->when($vehiculeIds->isNotEmpty(), fn (Builder $q2) => $q2->orWhereIn('vehicule_id', $vehiculeIds))
                ->when($equipeIds->isNotEmpty(), fn (Builder $q2) => $q2->orWhereIn('equipe_livraison_id', $equipeIds))
            )
            ->when($filters['type'] === 'logistique' && $filters['statut'], fn (Builder $q) => $q->where('statut', $filters['statut']))
            ->when($filters['date_debut'], fn (Builder $q) => $q->whereDate('date_depart_reelle', '>=', $filters['date_debut']))
            ->when($filters['date_fin'], fn (Builder $q) => $q->whereDate('date_depart_reelle', '<=', $filters['date_fin']))
            ->orderByDesc('date_depart_reelle')
            ->limit(200)
            ->get()
            ->map(function (TransfertLogistique $t) {
                $date = $t->date_depart_reelle ?? $t->created_at;

                return [
                    'id' => $t->id,
                    'type' => 'logistique',
                    'reference' => $t->reference,
                    'statut' => $t->statut?->value,
                    'statut_label' => $t->statut_label,
                    'site_source' => $t->siteSource?->nom ?? '—',
                    'site_destination' => $t->siteDestination?->nom ?? '—',
                    'vehicule' => $t->vehicule ? [
                        'id' => $t->vehicule->id,
                        'nom_vehicule' => $t->vehicule->nom_vehicule,
                        'immatriculation' => $t->vehicule->immatriculation,
                    ] : null,
                    'date' => $date?->toDateString(),
                    'date_sort' => $date?->timestamp ?? 0,
                    'nb_packs' => (int) $t->lignes->sum('quantite_chargee'),
                ];
            });
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
