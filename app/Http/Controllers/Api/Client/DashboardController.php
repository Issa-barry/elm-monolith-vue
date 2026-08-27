<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\DashboardMineRequest;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Data\ComparisonPeriod;
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

            `summary_evolution` (champ additif, cf. rapport du 27/08/2026) : évolution
            de chacun des 5 champs de `summary` entre la période sélectionnée et la
            période **immédiatement précédente de même durée** (ex : 01/08→31/08 est
            comparé à 01/07→31/07 ; 10/08→16/08 [7 jours] est comparé à 03/08→09/08
            [7 jours] — jamais "le mois précédent" arbitraire). `direction` est
            **factuelle** (`up`/`down`/`stable`), jamais un jugement métier : une
            hausse de `frais_depenses_total` vaut `up` exactement comme une hausse de
            `total_earned` — c'est au frontend de décider, KPI par KPI, si une hausse
            donnée est une bonne ou une mauvaise nouvelle. Quand la période précédente
            valait 0 et que la période actuelle est non nulle, le pourcentage n'est pas
            défini mathématiquement : `percent` vaut alors `null` et `comparable` vaut
            `false` (jamais `Infinity`/`999999`/`100` en substitut) — `direction` reste
            renseignée pour permettre d'afficher une flèche, typiquement à côté d'un
            texte comme "Nouveau" plutôt que d'un pourcentage. `summary_evolution` et
            `comparison_period` sont tous les deux `null` uniquement dans le cas
            dégénéré `period=custom` sans `date_debut`/`date_fin` (aucune période
            résolue, donc aucune période précédente calculable).

            `comparison_period` : bornes exactes de la période précédente utilisée par
            `summary_evolution`, pour affichage (ex. "vs 01/07 - 31/07").
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

        // `period=custom` sans date_debut/date_fin (cf. filterValidationRules() : les
        // deux sont `nullable`, aucun `required_if:period,custom`) reste un cas
        // dégénéré préexistant traité comme "sans filtre de date" par summary() —
        // aucune période précédente n'est alors calculable, summary_evolution et
        // comparison_period restent `null` plutôt que de planter ou d'inventer une
        // borne arbitraire. Une SEULE affectation par variable (ternaire), pas un
        // `if` avec deux instructions séparées : Scramble n'unifie pas correctement
        // le type `null` d'un branchement avec le type concret d'un autre quand ce
        // sont deux `$var = ...` distincts sur des chemins différents (vérifié
        // empiriquement le 27/08/2026 sur ce même contrat — cf. le même correctif
        // appliqué à `KpiEvolutionCalculator::compare()`) ; un ternaire, en
        // revanche, est bien inféré comme une union des deux branches.
        $hasResolvedPeriod = $filters['date_debut'] !== null && $filters['date_fin'] !== null;

        $comparisonPeriod = $hasResolvedPeriod
            ? new ComparisonPeriod(...$this->earningsService->previousPeriodBounds($filters['date_debut'], $filters['date_fin']))
            : null;

        $summaryEvolution = $hasResolvedPeriod
            ? $this->earningsService->summaryEvolution(
                $earnings['totals'],
                $organizationId,
                $proprietaire,
                $livreur,
                $filters['date_debut'],
                $filters['date_fin'],
                $filters['statut'],
                $vehiculeIdsContrainte
            )
            : null;

        return response()->json([
            'filters' => $filters,
            'summary' => $earnings['totals'],
            'summary_evolution' => $summaryEvolution,
            'comparison_period' => $comparisonPeriod,
            'par_vehicule' => $earnings['by_vehicule'],
            'vehicules' => $vehicules->map(fn (Vehicule $v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
            ])->values(),
        ]);
    }
}
