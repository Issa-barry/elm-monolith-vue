<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\DepensesMineRequest;
use App\Http\Resources\Api\Client\DepenseResource;
use App\Models\Depense;
use App\Models\User;
use App\Services\Client\BeneficiairesCompteResolver;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\ClientIdentityResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dépenses consolidées (tous véhicules accessibles) — comble le manque
 * documenté au §4/§8 de docs/api-espace-client-contract.md : jusqu'ici seul
 * `GET /v1/mobile/vehicules/{id}/frais` (un véhicule à la fois) existait,
 * obligeant un appel N+1 pour une page "Mes dépenses" globale.
 *
 * Accessible au proprietaire ET au livreur (même périmètre que
 * VehiculeFraisController, dont c'est la version consolidée) — volontairement
 * PLUS LARGE que ClientEarningsService::fraisDepensesParVehicule() (qui, lui,
 * est proprietaire-only car il nourrit un calcul de solde à payer, pas une
 * simple consultation). Chaque activité du compte (propriétaire, livreur, parrain,
 * consultant) voit aussi les dépenses rattachées directement à elle (ex. avance sur salaire,
 * `vehicule` vaut alors null), sauf filtre véhicule : toute dépense que le reste à payer
 * déduit (ClientEarningsService::deductionsParActivite()) figure donc dans cette liste.
 *
 * `total_valide` : somme des dépenses validées correspondant aux filtres, toutes
 * pages confondues. C'est le montant de la carte « Dépenses » de l'accueil mobile.
 */
class DepensesController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly BeneficiairesCompteResolver $beneficiairesResolver,
        private readonly ClientEarningsService $earningsService,
    ) {}

    public function __invoke(DepensesMineRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $this->identityResolver->resolve($user);
        $beneficiaires = $this->beneficiairesResolver->depuisIdentite($user, $identity);

        $filters = $request->filters();

        $vehiculeIds = $this->earningsService
            ->vehiculesAccessibles($identity->organizationId, $identity->proprietaire, $identity->livreur)
            ->pluck('id')
            ->all();

        $query = Depense::query()
            ->with([
                'depenseType:id,libelle,code',
                'vehiculeBeneficiaire:id,nom_vehicule,immatriculation',
            ])
            ->where('organization_id', $identity->organizationId)
            ->where(function ($q) use ($filters, $vehiculeIds, $beneficiaires) {
                $q->where('beneficiaire_type', 'vehicule')
                    ->when(
                        $filters['vehicule_id'] !== null,
                        fn ($sq) => $sq->whereIn('beneficiaire_id', array_intersect($vehiculeIds, [$filters['vehicule_id']])),
                        fn ($sq) => $sq->whereIn('beneficiaire_id', $vehiculeIds)
                    );

                if ($filters['vehicule_id'] === null) {
                    $q->orWhere(fn ($sq) => $beneficiaires->restreindreParts($sq));
                }
            })
            ->when($filters['depense_type_id'], fn ($q) => $q->where('depense_type_id', $filters['depense_type_id']))
            ->when($filters['statut'], fn ($q) => $q->where('statut', $filters['statut']))
            ->when($filters['date_debut'], fn ($q) => $q->whereDate('date_depense', '>=', $filters['date_debut']))
            ->when($filters['date_fin'], fn ($q) => $q->whereDate('date_depense', '<=', $filters['date_fin']));

        $totalValide = round((float) (clone $query)->where('statut', StatutDepense::VALIDE->value)->sum('montant'), 2);

        $depenses = $query
            ->orderByDesc('date_depense')
            ->paginate($request->perPage())
            ->withQueryString();

        return DepenseResource::collection($depenses)->additional([
            'filters' => $filters,
            'total_valide' => $totalValide,
        ]);
    }
}
