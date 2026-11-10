<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\ActiviteMineRequest;
use App\Models\CommandeVente;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Data\ActiviteRow;
use App\Services\Client\Data\VehiculeSummary;
use App\Services\Client\Operations\OperationsCompte;
use App\Services\Client\Operations\PresentationOperation;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Liste unique « Commandes » de l'espace client (ADR 0058) : commandes de vente et transferts
 * logistiques du compte, tous liens confondus. Le périmètre vient d'`OperationsCompte`, partagé avec le
 * détail et le scan.
 *
 * Pagination sans UNION SQL : deux modèles différents sont filtrés en base, comptés, puis seules les
 * `page × per_page` premières lignes de chacun sont chargées, fusionnées et triées : ces lignes
 * contiennent forcément la page demandée.
 *
 * La date filtrée et triée est celle affichée sur la ligne : validation ou départ réel, à défaut création.
 */
class ActiviteController extends Controller
{
    public function __construct(private readonly ClientIdentityResolver $identityResolver) {}

    #[Endpoint(
        description: 'Liste unique des opérations du compte : une vente concernant le compte à plusieurs '
            .'titres n\'apparaît qu\'une fois. Chaque ligne porte `nature` (ce qu\'est l\'opération), `liens` '
            .'(`client`, `livreur`, `proprietaire` : pourquoi ce compte la voit) et `avancement` (vocabulaire '
            .'commun, calculé par le serveur). Le paiement reste indépendant de l\'avancement et vaut null '
            .'pour un compte seulement livreur. `lien` (`client`, `livreur`, `proprietaire`) ne garde que les '
            .'opérations vues à ce titre, chaque ligne gardant tous ses liens. `statut` et `statut_label` répètent `avancement`, retirés '
            .'après le déploiement du front. `compte_a_des_operations` ignore les filtres et distingue un '
            .'compte sans aucune opération d\'une période vide. `periode` (période rapide résolue par le '
            .'serveur) exclut `date_debut`/`date_fin` (422 sinon). Période comptable : `annee` + `mois` + '
            .'`quinzaine` (`P1` du 1er au 15, `P2` du 16 à la fin du mois, `TOUT` ou omise = le mois), bornes '
            .'calculées par le serveur, exclut `periode` et `date_debut`/`date_fin`.',
    )]
    public function __invoke(ActiviteMineRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $operations = new OperationsCompte($this->identityResolver->resolve($user));

        $filters = $request->filters();
        $aDesOperations = $operations->aDesOperations();

        if (! $aDesOperations) {
            return $this->emptyPage($request);
        }

        $bornes = $request->bornes();
        $perPage = $request->perPage();
        $page = $request->page();
        $fenetre = $page * $perPage;

        $items = collect();
        $total = 0;

        if ($filters['type'] !== 'logistique') {
            $ventes = $operations->ventes($filters['lien'])
                ->when($filters['vehicule_id'] !== null, fn (Builder $q) => $q->where('vehicule_id', $filters['vehicule_id']))
                ->tap(fn (Builder $q) => $this->filtrerPeriode($q, OperationsCompte::DATE_VENTE, $bornes));
            $total += $ventes->count();
            $items = $items->concat($this->ventes($ventes, $fenetre, $operations));
        }

        if ($filters['type'] !== 'vente') {
            $transferts = $operations->transferts($filters['lien'])
                ->when($filters['vehicule_id'] !== null, fn (Builder $q) => $q->where('vehicule_id', $filters['vehicule_id']))
                ->tap(fn (Builder $q) => $this->filtrerPeriode($q, OperationsCompte::DATE_TRANSFERT, $bornes));
            $total += $transferts->count();
            $items = $items->concat($this->transferts($transferts, $fenetre, $operations));
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
            'compte_a_des_operations' => $aDesOperations,
        ]);
    }

    private function emptyPage(ActiviteMineRequest $request): JsonResponse
    {
        return response()->json([
            'data' => [],
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $request->perPage(), 'total' => 0],
            'filters' => $request->filters(),
            'compte_a_des_operations' => false,
        ]);
    }

    /** @return Collection<int, ActiviteRow> */
    private function ventes(Builder $query, int $limite, OperationsCompte $operations): Collection
    {
        $ventes = $query
            ->with([
                'site:id,nom',
                'vehicule:id,nom_vehicule,immatriculation',
                'client:id,nom,prenom',
                'lignes:id,commande_vente_id,quantite_demandee',
                'facture:id,commande_vente_id,statut_facture,montant_rembourse',
            ])
            ->orderByRaw(OperationsCompte::DATE_VENTE.' DESC')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        $liens = $operations->liensVentes($ventes);

        return $ventes->toBase()->map(function (CommandeVente $c) use ($liens) {
            $date = $c->validated_at ?? $c->created_at;
            $paiement = PresentationOperation::paiementVente($c, $liens[$c->id]);

            return new ActiviteRow(
                id: $c->id,
                type: 'vente',
                reference: $c->reference ?? '—',
                nature: PresentationOperation::natureVente($c),
                liens: $liens[$c->id],
                avancement: PresentationOperation::avancementVente($c),
                siteSource: $c->site?->nom ?? '—',
                siteDestination: $c->client?->nom_complet ?? 'Vente directe',
                vehicule: $c->vehicule ? new VehiculeSummary(
                    id: $c->vehicule->id,
                    nomVehicule: $c->vehicule->nom_vehicule,
                    immatriculation: $c->vehicule->immatriculation,
                ) : null,
                date: $date?->toDateString(),
                dateSort: $date?->timestamp ?? 0,
                nbPacks: (int) $c->lignes->sum('quantite_demandee'),
                statutPaiement: $paiement['statut'],
                statutPaiementLabel: $paiement['label'],
            );
        });
    }

    /** @return Collection<int, ActiviteRow> */
    private function transferts(Builder $query, int $limite, OperationsCompte $operations): Collection
    {
        $transferts = $query
            ->with(['siteSource:id,nom', 'siteDestination:id,nom', 'vehicule:id,nom_vehicule,immatriculation', 'lignes'])
            ->orderByRaw(OperationsCompte::DATE_TRANSFERT.' DESC')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        $liens = $operations->liensTransferts($transferts);

        return $transferts->toBase()->map(function (TransfertLogistique $t) use ($liens) {
            $date = $t->date_depart_reelle ?? $t->created_at;

            return new ActiviteRow(
                id: $t->id,
                type: 'logistique',
                reference: $t->reference,
                nature: PresentationOperation::natureTransfert(),
                liens: $liens[$t->id],
                avancement: PresentationOperation::avancementTransfert($t),
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
}
