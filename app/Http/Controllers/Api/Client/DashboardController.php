<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\ClientIdentityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard financier de l'espace client — même moteur (`ClientEarningsService`)
 * et mêmes filtres (`ClientEarningsService::resolveFilters()`) que le dashboard
 * Inertia (`ClientDashboardController::index()`), pour garantir que backoffice
 * web et Nuxt/mobile affichent exactement les mêmes montants. Cf.
 * tests/Feature/Api/Client/DashboardControllerTest.php pour les tests de parité.
 *
 * Le nom "dashboard" (et pas "gains") est un choix délibéré : distinct de
 * `GET gains/mine` (GainsController), un endpoint mobile existant, plus ancien,
 * qui ne calcule que les commissions de vente (aucune commission logistique,
 * aucune dépense/solde) — un moteur divergent, conservé tel quel pour ne pas
 * casser un contrat mobile existant, mais dont l'usage est déconseillé au
 * profit de celui-ci pour tout nouvel écran (voir docs/api-espace-client-contract.md).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ClientIdentityResolver $identityResolver,
        private readonly ClientEarningsService $earningsService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $this->earningsService->resolveFilters($request);

        $identity = $this->identityResolver->resolve($user);
        $organizationId = $identity->organizationId;
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

        $vehicules = $this->earningsService->vehiculesAccessibles($organizationId, $proprietaire, $livreur);

        $vehiculeIdsAccessibles = $vehicules->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $vehiculeIdsContrainte = null;
        if ($filters['vehicule_id'] !== null && in_array($filters['vehicule_id'], $vehiculeIdsAccessibles, true)) {
            $vehiculeIdsContrainte = [$filters['vehicule_id']];
        }

        $earnings = $this->earningsService->summary(
            $vehicules,
            $organizationId,
            $proprietaire,
            $livreur,
            $filters['date_debut'],
            $filters['date_fin'],
            $filters['statut'],
            $vehiculeIdsContrainte
        );

        return response()->json([
            'filters' => $filters,
            'summary' => $earnings['totals'],
            'par_vehicule' => $earnings['by_vehicule'],
            'vehicules' => $vehicules->map(fn (Vehicule $v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
            ])->values(),
        ]);
    }
}
