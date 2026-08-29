<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\DepensesMineRequest;
use App\Http\Resources\Api\Client\DepenseResource;
use App\Models\Depense;
use App\Models\User;
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
 * simple consultation).
 */
class DepensesController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ClientEarningsService $earningsService,
    ) {}

    public function __invoke(DepensesMineRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $identity = $this->identityResolver->resolve($user);

        $filters = $request->filters();

        $vehiculeIds = $this->earningsService
            ->vehiculesAccessibles($identity->organizationId, $identity->proprietaire, $identity->livreur)
            ->pluck('id')
            ->all();

        $depenses = Depense::query()
            ->with([
                'depenseType:id,libelle,code',
                'vehiculeBeneficiaire:id,nom_vehicule,immatriculation',
            ])
            ->where('beneficiaire_type', 'vehicule')
            ->where('organization_id', $identity->organizationId)
            ->when(
                $filters['vehicule_id'] !== null,
                fn ($q) => $q->whereIn('beneficiaire_id', array_intersect($vehiculeIds, [$filters['vehicule_id']])),
                fn ($q) => $q->whereIn('beneficiaire_id', $vehiculeIds)
            )
            ->when($filters['depense_type_id'], fn ($q) => $q->where('depense_type_id', $filters['depense_type_id']))
            ->when($filters['statut'], fn ($q) => $q->where('statut', $filters['statut']))
            ->when($filters['date_debut'], fn ($q) => $q->whereDate('date_depense', '>=', $filters['date_debut']))
            ->when($filters['date_fin'], fn ($q) => $q->whereDate('date_depense', '<=', $filters['date_fin']))
            ->orderByDesc('date_depense')
            ->paginate($request->perPage())
            ->withQueryString();

        return DepenseResource::collection($depenses)->additional(['filters' => $filters]);
    }
}
