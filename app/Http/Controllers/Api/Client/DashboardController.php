<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\DashboardMineRequest;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\ClientIdentityResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;

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

    #[Endpoint(
        title: 'Dashboard financier (résumé + détail par véhicule)',
        description: <<<'MD'
            Montants en **GNF** (franc guinéen), sans décimales dans la pratique mais
            typés nombre (jamais des centimes, jamais un float à diviser par 100).

            Définitions exactes des champs de `summary` (mêmes noms, mêmes valeurs que
            le dashboard Inertia — `App\Services\Client\ClientEarningsService`) :
            - `total_earned` : commissions de vente + logistique gagnées sur la période (brut dû).
            - `total_paid` : déjà versé sur ces mêmes commissions.
            - `frais_depenses_total` : dépenses véhicule validées sur la période (proprietaire uniquement — jamais imputées à un livreur dans ce moteur).
            - `balance` : `total_earned - frais_depenses_total - total_paid`, **jamais négatif** (plancher à 0 — un solde négatif n'est jamais affiché comme dette du propriétaire).
            - `operations_count` : nombre de commissions (vente + logistique) entrant dans `total_earned`.

            `par_vehicule` : même détail que `summary` mais par véhicule (schéma
            `VehiculeEarningsRow`), **toujours la liste complète du parc accessible**
            même si `vehicule_id` filtre le calcul (les véhicules hors filtre
            apparaissent avec des montants à 0) — ce n'est pas un bug.

            `filters.period` : raccourci appliqué (`custom` = `date_debut`/`date_fin`
            pris tels quels, sinon calculés serveur — jamais un défaut inventé côté
            frontend).
            MD,
    )]
    public function __invoke(DashboardMineRequest $request): JsonResponse
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
