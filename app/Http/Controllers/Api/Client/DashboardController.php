<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\DashboardMineRequest;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\BeneficiairesCompteResolver;
use App\Services\Client\ClientEarningsService;
use App\Services\Client\Data\ComparisonPeriod;
use App\Services\Client\Data\VehiculeLieSummary;
use App\Services\Client\Data\VehiculeRattache;
use App\Services\Client\VehiculesRattachesService;
use App\Support\Periodes\PeriodeComptableFiltre;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Dashboard financier de l'espace client — même moteur (`ClientEarningsService`)
 * et mêmes filtres (`ClientEarningsService::resolveFilters()`) que le dashboard
 * Inertia (`IndexClientDashboardController`), pour garantir que backoffice
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
        private readonly BeneficiairesCompteResolver $beneficiairesResolver,
        private readonly ClientEarningsService $earningsService,
        private readonly VehiculesRattachesService $vehiculesRattaches,
    ) {}

    #[Endpoint(
        title: 'Dashboard financier (résumé + détail par véhicule)',
        description: <<<'MD'
            Montants en **GNF** (franc guinéen), sans décimales dans la pratique mais
            typés nombre (jamais des centimes, jamais un float à diviser par 100).

            Toutes les activités du compte sont comptées (ADR 0054) : propriétaire, livreur, parrain et
            consultant. Chaque part l'est une seule fois, dans l'activité de son bénéficiaire (la part du
            parrain est déjà prise sur l'enveloppe Livreur). Une commission annulée n'entre dans aucun
            montant.

            Définitions exactes des champs de `summary` (mêmes noms, mêmes valeurs que
            le dashboard Inertia — `App\Services\Client\ClientEarningsService`) :
            - `total_earned` : commissions gagnées sur la période, toutes activités (brut dû, parts « Créée » comprises).
            - `total_paid` : déjà versé sur ces mêmes commissions.
            - `en_attente_validation` : part de `total_earned` encore « Créée » (période de paiement pas encore validée).
            - `frais_depenses_total` : dépenses validées que les fiches de paiement déduisent sur la période (dépenses imputées à chaque bénéficiaire du compte, et dépenses des véhicules du propriétaire). Filtré sur un véhicule : seules les dépenses de ce véhicule.
            - `balance` (reste à payer) : par activité `total_earned - en_attente_validation - frais_depenses_total - total_paid`, **jamais négatif**, puis additionné.
            - `operations_count` : nombre de parts entrant dans `total_earned`.

            `par_activite` : les mêmes champs par activité du compte (toutes ses activités, même à 0) ;
            leur somme donne `summary`.

            `depenses_total` (hors `summary`) : égal à `frais_depenses_total`.

            `par_vehicule` : même détail que `summary` mais par véhicule (schéma
            `VehiculeEarningsRow`), **toujours la liste complète des véhicules rattachés**
            (propriétaire, livreur de l'équipe actuelle, parrain) même si `vehicule_id` filtre le calcul
            (les véhicules hors filtre apparaissent avec des montants à 0) — ce n'est pas un bug. S'y
            ajoute tout véhicule portant une part du compte sur la période (équipe quittée, commission
            de consultant), avec `liens` vide. `liens` : nature du rattachement ; `par_activite` : gains
            du compte sur ce véhicule, par activité.

            `vehicules` : véhicules rattachés puis véhicules portant une part du compte (toutes
            périodes), avec `liens` ; c'est la liste acceptée par le filtre `vehicule_id` (ignoré sinon).

            `filters.period` : raccourci appliqué (`custom` = `date_debut`/`date_fin`
            pris tels quels, sinon calculés serveur — jamais un défaut inventé côté
            frontend).

            Filtre de période de l'accueil, exclusif de `period`/`date_debut`/`date_fin` (422 sinon) :
            soit `periode` (période rapide, `tout` = toutes les périodes), soit `annee` + `mois` +
            `quinzaine` (`P1` du 1er au 15, `P2` du 16 à la fin du mois, `TOUT` ou omise = le mois ;
            `mois` omis = l'année). Bornes calculées par le serveur et renvoyées dans `filters`
            (`period` vaut alors `custom`). Ventes et logistique y sont datées par leur date de gain,
            comme les fiches de paiement ; aucune formule ne change.

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

        $bornes = $request->bornesPeriode();
        $filters = $this->filtres($request, $bornes);
        $colonneDateLogistique = $bornes !== null
            ? ClientEarningsService::DATE_LOGISTIQUE_GAIN
            : ClientEarningsService::DATE_LOGISTIQUE_CREATION;

        $beneficiaires = $this->beneficiairesResolver->resolve($user);

        $rattaches = $this->vehiculesRattaches->pour($beneficiaires);
        $vehicules = $this->listeVehicules($rattaches, $this->earningsService->vehiculesDesParts($beneficiaires));

        $vehiculeIdsAccessibles = array_map(fn (VehiculeLieSummary $v) => $v->id, $vehicules);
        $vehiculeIdsContrainte = null;
        if ($filters['vehicule_id'] !== null && in_array($filters['vehicule_id'], $vehiculeIdsAccessibles, true)) {
            $vehiculeIdsContrainte = [$filters['vehicule_id']];
        }

        $earnings = $this->earningsService->summary(
            $rattaches,
            $beneficiaires,
            $filters['date_debut'],
            $filters['date_fin'],
            $filters['statut'],
            $vehiculeIdsContrainte,
            $colonneDateLogistique
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
                $beneficiaires,
                $filters['date_debut'],
                $filters['date_fin'],
                $filters['statut'],
                $vehiculeIdsContrainte,
                $colonneDateLogistique
            )
            : null;

        return response()->json([
            'filters' => $filters,
            'summary' => $earnings['totals'],
            'par_activite' => $earnings['par_activite'],
            'depenses_total' => $earnings['totals']['frais_depenses_total'],
            'summary_evolution' => $summaryEvolution,
            'comparison_period' => $comparisonPeriod,
            'par_vehicule' => $earnings['by_vehicule'],
            'vehicules' => $vehicules,
        ]);
    }

    /**
     * Véhicules rattachés (avec leurs liens), puis ceux qui portent seulement une part du compte.
     *
     * @param  Collection<int, VehiculeRattache>  $rattaches
     * @param  Collection<int, Vehicule>  $desParts
     * @return array<int, VehiculeLieSummary>
     */
    private function listeVehicules(Collection $rattaches, Collection $desParts): array
    {
        $connus = $rattaches->map(fn (VehiculeRattache $r) => (string) $r->vehicule->id)->all();
        $lignes = $rattaches
            ->map(fn (VehiculeRattache $r) => [$r->vehicule, $r->liensValeurs()])
            ->concat($desParts
                ->reject(fn (Vehicule $v) => in_array((string) $v->id, $connus, true))
                ->map(fn (Vehicule $v) => [$v, []]));

        return $lignes
            ->map(fn (array $ligne) => new VehiculeLieSummary(
                id: (string) $ligne[0]->id,
                nomVehicule: (string) $ligne[0]->nom_vehicule,
                immatriculation: (string) $ligne[0]->immatriculation,
                liens: $ligne[1],
            ))
            ->values()
            ->all();
    }

    /**
     * Filtres du moteur partagé, complétés par le filtre de période de l'accueil (propre à cette API).
     *
     * @param  array{0: ?CarbonImmutable, 1: ?CarbonImmutable}|null  $bornes
     */
    private function filtres(DashboardMineRequest $request, ?array $bornes): array
    {
        $filters = $this->earningsService->resolveFilters($request);
        $periodeComptable = PeriodeComptableFiltre::depuisRequete($request);

        return [
            ...$filters,
            'period' => $bornes !== null ? 'custom' : $filters['period'],
            'date_debut' => $bornes !== null ? $bornes[0]?->toDateString() : $filters['date_debut'],
            'date_fin' => $bornes !== null ? $bornes[1]?->toDateString() : $filters['date_fin'],
            'periode' => $request->input('periode'),
            'annee' => $periodeComptable?->annee,
            'mois' => $periodeComptable?->mois,
            'quinzaine' => $periodeComptable?->quinzaine,
        ];
    }
}
