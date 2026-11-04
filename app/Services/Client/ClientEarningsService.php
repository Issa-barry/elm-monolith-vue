<?php

namespace App\Services\Client;

use App\Enums\ActiviteCompte;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\Client\Data\ActiviteEarningsRow;
use App\Services\Client\Data\SummaryEvolution;
use App\Services\Client\Data\VehiculeEarningsRow;
use App\Services\Client\Data\VehiculeRattache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Moteur financier unique de l'espace client (gains, dépenses, solde) — SOURCE
 * DE VÉRITÉ PARTAGÉE entre l'espace client Inertia (`ClientDashboardPayloadBuilder`)
 * et l'API (DashboardController, cf. docs/api-espace-client-contract.md). Toute
 * évolution de ce calcul doit se faire ICI, jamais dans un contrôleur, pour que
 * les deux surfaces ne puissent plus jamais diverger.
 *
 * Extrait le 26/08/2026 de ClientDashboardController (qui avait ces méthodes en
 * privé) — comportement préservé à l'identique, à DEUX corrections près,
 * toutes deux découvertes en écrivant le test de non-régression
 * `ClientDashboardTest::test_dashboard_exposes_vente_commission_in_earnings_by_vehicule`
 * (jamais couvertes avant : ClientDashboardTest ne vérifiait que les totaux
 * agrégés, jamais le détail par véhicule d'une commission de vente) :
 *
 * 1. earningsByVehicule() lisait `$part->commission?->vehicule` pour les parts
 *    de VENTE (CommissionEnveloppePart), qui n'a pourtant AUCUNE relation
 *    `commission` (seulement `enveloppe()`, cf. CommissionEnveloppePart.php) —
 *    cet appel renvoyait donc toujours null et EXCLUAIT SILENCIEUSEMENT toutes
 *    les commissions de vente du détail "solde par véhicule" (seules les
 *    commissions logistiques y apparaissaient), alors que releve() dans le
 *    même fichier utilisait déjà le bon chemin (`enveloppe->source->vehicule`)
 *    pour la même donnée. Corrigé avec le chemin déjà éprouvé par releve().
 *
 * 2. Ce chemin "déjà éprouvé" était en réalité TOUT AUSSI cassé : l'eager-load
 *    `'enveloppe.source:id,reference,validated_at,created_at'` (dans
 *    partsVentes()) restreignait les colonnes chargées sur `source`
 *    (CommandeVente) sans inclure `vehicule_id` — la clé étrangère nécessaire
 *    à la relation `vehicule()` juste après dans le même `with()`. Sans elle,
 *    `$commande->vehicule` résout silencieusement à null, que ce soit dans
 *    earningsByVehicule() (corrigé au point 1) OU dans releve() (bug
 *    préexistant, jamais détecté non plus : `vehicule_nom` y retombait
 *    toujours sur `'-'` pour une ligne de vente). Corrigé en ajoutant
 *    `vehicule_id` à la liste de colonnes sélectionnées.
 *
 * 3. releve() lit aussi `$commande?->validated_at` (préféré à `created_at`
 *    pour dater une ligne de vente) — `CommandeVente::validated_at` n'était
 *    NI fillable NI casté en `datetime` (cf. CommandeVente.php avant ce même
 *    correctif). Conséquence réelle en production : `PdvCheckoutService`
 *    passe explicitement `'validated_at' => now()` à `CommandeVente::create()`
 *    à chaque checkout, mais Eloquent l'ignorait silencieusement (absent de
 *    `$fillable`) — la colonne restait NULL pour toute commande jamais créée,
 *    rendant `ScanCommandeController`/`LivraisonsEnCoursController` (qui lisent
 *    `$commande->validated_at?->toDateString()`) inertes eux aussi (toujours
 *    null). Un test factory (qui contourne `$fillable`) l'a fait apparaître :
 *    sans cast, une valeur effectivement présente redevient une chaîne brute
 *    au rechargement, et `->format()`/`->toDateString()` plante. Corrigé en
 *    ajoutant `validated_at` à `$fillable` ET `casts()` de CommandeVente — fait
 *    enfin fonctionner l'intention déjà écrite dans PdvCheckoutService, sans
 *    changer aucune formule ici.
 *
 * Gains multi-activités (ADR 0054) : les parts se lisent pour TOUS les bénéficiaires du compte
 * (`BeneficiairesCompte` : propriétaire, livreur, parrain, consultants), chacune comptée une seule
 * fois dans l'activité de son `beneficiaire_type` — la part du parrain étant déjà retirée de
 * l'enveloppe Livreur, aucune enveloppe n'est jamais additionnée. Une part annulée reste dans le
 * relevé mais sort de tous les montants. `balance` (« reste à payer ») suit la règle des fiches de
 * paiement : parts sorties de « Créée », moins les dépenses validées que la fiche du bénéficiaire
 * déduit sur la période, moins le déjà versé, par activité et jamais sous 0. Aucune formule du
 * moteur de commissions n'est touchée : ce service ne fait que lire.
 */
class ClientEarningsService
{
    /** Date historique du filtre des parts logistiques (period, date_debut/date_fin). */
    public const DATE_LOGISTIQUE_CREATION = 'created_at';

    /** Date de gain, celle des périodes de paiement (PeriodeCalculatorService) : filtre période comptable. */
    public const DATE_LOGISTIQUE_GAIN = 'earned_at';

    /**
     * @param  Collection<int, VehiculeRattache>  $vehicules  Véhicules rattachés au compte, déjà résolus
     *                                                        par l'appelant (VehiculesRattachesService).
     * @param  array<string>|null  $vehiculeIds  Restreint aux véhicules donnés (filtre utilisateur) ;
     *                                           `null` = tous les véhicules.
     */
    public function summary(
        Collection $vehicules,
        BeneficiairesCompte $beneficiaires,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null,
        string $colonneDateLogistique = self::DATE_LOGISTIQUE_CREATION,
    ): array {
        $toutesParts = $this->avecOperation($this->partsQuery($beneficiaires, $dateDebut, $dateFin, $statut, $vehiculeIds))
            ->latest('id')
            ->get();
        $parts = $this->sansAnnulees($toutesParts);
        $partsLogistiques = $this->partsLogistiques($beneficiaires, $dateDebut, $dateFin, $statut, $vehiculeIds, $colonneDateLogistique);
        $fraisParVehicule = $this->fraisDepensesParVehicule($beneficiaires->organizationId, $beneficiaires->proprietaire, $dateDebut, $dateFin, $vehiculeIds);
        $deductions = $this->deductionsParActivite($beneficiaires, $dateDebut, $dateFin, $vehiculeIds, $fraisParVehicule);

        $parActivite = $this->parActivite($beneficiaires, $parts, $partsLogistiques, $deductions);

        return [
            'totals' => $this->totaux($parActivite),
            'par_activite' => $parActivite,
            'by_vehicule' => $this->earningsByVehicule($vehicules, $parts, $partsLogistiques, $fraisParVehicule),
            'statement' => $this->releve($toutesParts, $partsLogistiques),
        ];
    }

    /**
     * Parts du compte, annulées comprises (le relevé et la liste les montrent, les montants les
     * excluent). Datées par la date de gain de l'enveloppe, comme les fiches de paiement.
     *
     * @param  array<string>|null  $vehiculeIds
     */
    public function partsQuery(
        BeneficiairesCompte $beneficiaires,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null,
        ?ActiviteCompte $activite = null,
    ): Builder {
        $query = CommissionEnveloppePart::query();

        if ($beneficiaires->estVide()) {
            return $query->whereRaw('1 = 0');
        }

        $organizationId = $beneficiaires->organizationId;

        return $query
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $organizationId))
            ->when($dateDebut, fn ($q) => $q->whereHas('enveloppe', fn ($sq) => $sq->whereDate('earned_at', '>=', $dateDebut)))
            ->when($dateFin, fn ($q) => $q->whereHas('enveloppe', fn ($sq) => $sq->whereDate('earned_at', '<=', $dateFin)))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($vehiculeIds !== null, function ($q) use ($vehiculeIds) {
                if ($vehiculeIds === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }
                $q->whereHas('enveloppe.source', fn ($sq) => $sq->whereIn('vehicule_id', $vehiculeIds));
            })
            ->tap(fn (Builder $q) => $beneficiaires->restreindreParts($q, $activite));
    }

    /** Charge l'opération (vente ou transfert), son véhicule et le processus de chaque part. */
    public function avecOperation(Builder $parts): Builder
    {
        return $parts->with([
            // vehicule_id doit être sélectionné ici : c'est la clé étrangère utilisée
            // par la relation `vehicule()` sur `source` juste en dessous — sans elle,
            // Eloquent charge un `source` sans FK et `source->vehicule` résout
            // silencieusement à null (bug distinct découvert le 26/08/2026 en écrivant
            // le test de non-régression de earningsByVehicule()).
            'enveloppe.source:id,reference,validated_at,created_at,vehicule_id',
            'enveloppe.source.vehicule:id,nom_vehicule,immatriculation',
            'enveloppe.processus:id,code',
        ]);
    }

    /**
     * Parts non annulées du compte, sur les mêmes filtres que summary().
     *
     * @param  array<string>|null  $vehiculeIds
     * @return Collection<int, CommissionEnveloppePart>
     */
    public function parts(
        BeneficiairesCompte $beneficiaires,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null,
        ?ActiviteCompte $activite = null,
    ): Collection {
        return $this->sansAnnulees(
            $this->partsQuery($beneficiaires, $dateDebut, $dateFin, $statut, $vehiculeIds, $activite)->get()
        );
    }

    /**
     * Parts du moteur logistique historique (avant le 03/09/2026, COMM-007) : seuls propriétaire et
     * livreur y existent.
     *
     * @return Collection<int, CommissionLogistiquePart>
     */
    public function partsLogistiques(
        BeneficiairesCompte $beneficiaires,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null,
        string $colonneDate = self::DATE_LOGISTIQUE_CREATION,
        ?ActiviteCompte $activite = null,
    ): Collection {
        $organizationId = $beneficiaires->organizationId;
        $proprietaire = in_array($activite, [null, ActiviteCompte::PROPRIETAIRE], true) ? $beneficiaires->proprietaire : null;
        $livreur = in_array($activite, [null, ActiviteCompte::LIVREUR], true) ? $beneficiaires->livreur : null;

        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return CommissionLogistiquePart::query()
            ->with([
                'commission.transfert:id,reference,date_arrivee_reelle,created_at',
                'commission.vehicule:id,nom_vehicule,immatriculation',
            ])
            ->whereHas('commission', fn ($query) => $query->where('organization_id', $organizationId))
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->when($dateDebut, fn ($q) => $q->whereDate($colonneDate, '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->whereDate($colonneDate, '<=', $dateFin))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($vehiculeIds !== null, function ($q) use ($vehiculeIds) {
                if ($vehiculeIds === []) {
                    $q->whereRaw('1 = 0');

                    return;
                }
                $q->whereHas('commission', fn ($sq) => $sq->whereIn('vehicule_id', $vehiculeIds));
            })
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere(function ($sq) use ($proprietaire) {
                        $sq->where('type_beneficiaire', 'proprietaire')
                            ->where('proprietaire_id', $proprietaire->id);
                    });
                }

                if ($livreur !== null) {
                    $query->orWhere(function ($sq) use ($livreur) {
                        $sq->where('type_beneficiaire', 'livreur')
                            ->where('livreur_id', $livreur->id);
                    });
                }
            })
            ->latest('id')
            ->get();
    }

    /**
     * Gains par activité du compte (toutes ses activités, même à 0 sur la période), chaque part dans
     * l'activité de son bénéficiaire. Montants en GNF, typés `float` (arrondis à 2 décimales).
     *
     * @param  Collection<int, CommissionEnveloppePart>  $parts  Parts non annulées.
     * @param  Collection<int, CommissionLogistiquePart>  $partsLogistiques
     * @param  array<string, float>  $deductions  activité => dépenses déduites (deductionsParActivite()).
     * @return array<int, ActiviteEarningsRow>
     */
    public function parActivite(BeneficiairesCompte $beneficiaires, Collection $parts, Collection $partsLogistiques, array $deductions): array
    {
        return collect($beneficiaires->activites())->map(function (ActiviteCompte $activite) use ($parts, $partsLogistiques, $deductions) {
            $lignes = $parts
                ->filter(fn (CommissionEnveloppePart $p) => $p->beneficiaire_type === $activite->beneficiaireType())
                ->concat($partsLogistiques->filter(fn (CommissionLogistiquePart $p) => $p->type_beneficiaire === $activite->value));

            $gagne = round((float) $lignes->sum(fn ($p) => $p->montant_a_payer), 2);
            $verse = round((float) $lignes->sum(fn ($p) => (float) $p->montant_verse), 2);
            $enAttente = round((float) $lignes
                ->filter(fn ($p) => $p->statut === StatutCommission::CREEE)
                ->sum(fn ($p) => $p->montant_a_payer), 2);
            $frais = round((float) ($deductions[$activite->value] ?? 0.0), 2);

            return new ActiviteEarningsRow(
                activite: $activite->value,
                libelle: $activite->label(),
                totalEarned: $gagne,
                totalPaid: $verse,
                enAttenteValidation: $enAttente,
                fraisDepensesTotal: $frais,
                balance: (float) max(0, round($gagne - $enAttente - $frais - $verse, 2)),
                operationsCount: $lignes->count(),
            );
        })->values()->all();
    }

    /**
     * Totaux du compte : somme exacte de `par_activite`. `balance` est la somme des restes par
     * activité (une avance d'une activité ne se compense jamais avec les gains d'une autre, comme
     * sur les fiches, une par bénéficiaire).
     *
     * @param  array<int, ActiviteEarningsRow>  $parActivite
     * @return array{total_earned: float, total_paid: float, en_attente_validation: float, frais_depenses_total: float, balance: float, operations_count: int}
     */
    public function totaux(array $parActivite): array
    {
        $somme = fn (string $champ): float => round((float) array_sum(array_map(fn (ActiviteEarningsRow $l) => $l->{$champ}, $parActivite)), 2);

        return [
            'total_earned' => $somme('totalEarned'),
            'total_paid' => $somme('totalPaid'),
            'en_attente_validation' => $somme('enAttenteValidation'),
            'frais_depenses_total' => $somme('fraisDepensesTotal'),
            'balance' => $somme('balance'),
            'operations_count' => (int) array_sum(array_map(fn (ActiviteEarningsRow $l) => $l->operationsCount, $parActivite)),
        ];
    }

    /**
     * Dépenses validées que les fiches de paiement déduisent de chaque activité sur la période
     * (PeriodeCalculatorService) : dépenses imputées au bénéficiaire, plus les dépenses de ses
     * véhicules pour le propriétaire. Filtrée sur un véhicule, seule la dépense de ce véhicule
     * s'y rattache.
     *
     * @param  array<string>|null  $vehiculeIds
     * @param  array<string, float>  $fraisParVehicule
     * @return array<string, float>
     */
    public function deductionsParActivite(
        BeneficiairesCompte $beneficiaires,
        ?string $dateDebut,
        ?string $dateFin,
        ?array $vehiculeIds,
        array $fraisParVehicule,
    ): array {
        $deductions = [];

        foreach ($beneficiaires->activites() as $activite) {
            $deductions[$activite->value] = $activite === ActiviteCompte::PROPRIETAIRE ? (float) array_sum($fraisParVehicule) : 0.0;

            if ($vehiculeIds !== null) {
                continue;
            }

            $deductions[$activite->value] += (float) Depense::query()
                ->where('organization_id', $beneficiaires->organizationId)
                ->where('statut', StatutDepense::VALIDE->value)
                ->where('beneficiaire_type', $activite->beneficiaireType())
                ->whereIn('beneficiaire_id', $beneficiaires->idsPour($activite))
                ->when($dateDebut, fn ($q) => $q->whereDate('date_depense', '>=', $dateDebut))
                ->when($dateFin, fn ($q) => $q->whereDate('date_depense', '<=', $dateFin))
                ->sum('montant');
        }

        return $deductions;
    }

    /**
     * Bornes de la période "immédiatement précédente, de même durée" qu'une
     * période donnée — règle unique utilisée pour toute comparaison de KPI
     * (cf. rapport du 27/08/2026) : jamais "le mois précédent" arbitraire
     * quand la période sélectionnée n'est pas un mois complet, toujours un
     * nombre de jours identique, qu'il s'agisse d'un raccourci (7j/30j/
     * ce_mois/mois_passe) ou d'une plage `custom`.
     *
     * Exemple : 01/08 → 31/08 (31 jours) donne 01/07 → 31/07 (31 jours).
     * Exemple : 10/08 → 16/08 (7 jours) donne 03/08 → 09/08 (7 jours).
     *
     * @return array{0: string, 1: string} [date_debut, date_fin]
     */
    public function previousPeriodBounds(string $dateDebut, string $dateFin): array
    {
        $debut = Carbon::parse($dateDebut)->startOfDay();
        $fin = Carbon::parse($dateFin)->startOfDay();
        $dureeEnJours = $debut->diffInDays($fin) + 1;

        $finPrecedente = $debut->copy()->subDay();
        $debutPrecedente = $finPrecedente->copy()->subDays($dureeEnJours - 1);

        return [$debutPrecedente->toDateString(), $finPrecedente->toDateString()];
    }

    /**
     * Évolution des 5 KPI de `totaux()` entre la période sélectionnée et la période précédente
     * de même durée (`previousPeriodBounds()`). Réutilise EXACTEMENT les mêmes requêtes que
     * `summary()`, seulement décalées dans le temps — aucune requête par véhicule.
     *
     * `$currentTotals` est le résultat déjà calculé de `totaux()` pour la période sélectionnée —
     * jamais recalculé ici, pour ne pas dupliquer ces requêtes une seconde fois.
     *
     * @param  array{total_earned: float, total_paid: float, frais_depenses_total: float, balance: float, operations_count: int}  $currentTotals
     */
    public function summaryEvolution(
        array $currentTotals,
        BeneficiairesCompte $beneficiaires,
        string $dateDebut,
        string $dateFin,
        ?string $statut = null,
        ?array $vehiculeIds = null,
        string $colonneDateLogistique = self::DATE_LOGISTIQUE_CREATION,
    ): SummaryEvolution {
        [$previousDateDebut, $previousDateFin] = $this->previousPeriodBounds($dateDebut, $dateFin);

        $partsPrecedentes = $this->parts($beneficiaires, $previousDateDebut, $previousDateFin, $statut, $vehiculeIds);
        $partsLogistiquesPrecedentes = $this->partsLogistiques($beneficiaires, $previousDateDebut, $previousDateFin, $statut, $vehiculeIds, $colonneDateLogistique);
        $fraisParVehiculePrecedent = $this->fraisDepensesParVehicule($beneficiaires->organizationId, $beneficiaires->proprietaire, $previousDateDebut, $previousDateFin, $vehiculeIds);
        $deductionsPrecedentes = $this->deductionsParActivite($beneficiaires, $previousDateDebut, $previousDateFin, $vehiculeIds, $fraisParVehiculePrecedent);

        $previousTotals = $this->totaux($this->parActivite($beneficiaires, $partsPrecedentes, $partsLogistiquesPrecedentes, $deductionsPrecedentes));

        return new SummaryEvolution(
            totalEarned: KpiEvolutionCalculator::compare((float) $currentTotals['total_earned'], (float) $previousTotals['total_earned']),
            totalPaid: KpiEvolutionCalculator::compare((float) $currentTotals['total_paid'], (float) $previousTotals['total_paid']),
            fraisDepensesTotal: KpiEvolutionCalculator::compare((float) $currentTotals['frais_depenses_total'], (float) $previousTotals['frais_depenses_total']),
            balance: KpiEvolutionCalculator::compare((float) $currentTotals['balance'], (float) $previousTotals['balance']),
            operationsCount: KpiEvolutionCalculator::compare((float) $currentTotals['operations_count'], (float) $previousTotals['operations_count']),
        );
    }

    /**
     * Montants en GNF, sans décimales dans la pratique mais typés `float`
     * (arrondis à 2 décimales) — jamais des centimes.
     *
     * L'accumulation ci-dessous reste un tableau associatif muté en boucle
     * (`+=`) — inchangé, c'est le calcul métier. Seule la toute dernière étape
     * (le `map()` final) construit un `VehiculeEarningsRow` par ligne, pour
     * que le contrat de sortie soit un objet typé plutôt qu'un array libre
     * (cf. docblock du DTO pour le pourquoi).
     *
     * Lignes : chaque véhicule rattaché (même à 0), puis tout véhicule portant une part du compte
     * sur la période (véhicule quitté, commission de consultant). Même règle de reste que totaux().
     *
     * @param  Collection<int, VehiculeRattache>  $vehicules
     * @param  Collection<int, CommissionEnveloppePart>  $partsVentes  Parts non annulées.
     * @param  Collection<int, CommissionLogistiquePart>  $partsLogistiques
     * @param  array<string, float>  $fraisParVehicule
     * @return list<VehiculeEarningsRow>
     */
    public function earningsByVehicule(Collection $vehicules, Collection $partsVentes, Collection $partsLogistiques, array $fraisParVehicule = []): array
    {
        $stats = [];

        $ligne = function (Vehicule $vehicule, array $liens = []) use (&$stats, $fraisParVehicule): void {
            $stats[$vehicule->id] ??= [
                'vehicule_id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                'total_earned' => 0.0,
                'total_paid' => 0.0,
                'en_attente' => 0.0,
                'liens' => $liens,
                'par_activite' => [],
            ];
        };

        $ajouter = function (?Vehicule $vehicule, ?ActiviteCompte $activite, $part) use (&$stats, $ligne): void {
            if ($vehicule === null) {
                return;
            }
            $ligne($vehicule);
            $stats[$vehicule->id]['total_earned'] += $part->montant_a_payer;
            $stats[$vehicule->id]['total_paid'] += (float) $part->montant_verse;
            if ($part->statut === StatutCommission::CREEE) {
                $stats[$vehicule->id]['en_attente'] += $part->montant_a_payer;
            }
            if ($activite !== null) {
                $stats[$vehicule->id]['par_activite'][$activite->value] = ($stats[$vehicule->id]['par_activite'][$activite->value] ?? 0.0) + $part->montant_a_payer;
            }
        };

        foreach ($vehicules as $rattache) {
            $ligne($rattache->vehicule, $rattache->liensValeurs());
        }

        foreach ($partsVentes as $part) {
            // Correctif du 26/08/2026 : CommissionEnveloppePart n'a pas de relation
            // `commission` (seulement `enveloppe`) — cf. docblock de la classe.
            $ajouter($part->enveloppe?->source?->vehicule, ActiviteCompte::depuisBeneficiaireType($part->beneficiaire_type), $part);
        }

        foreach ($partsLogistiques as $part) {
            $ajouter($part->commission?->vehicule, ActiviteCompte::tryFrom((string) $part->type_beneficiaire), $part);
        }

        return collect($stats)
            ->map(function (array $row) {
                $totalEarned = round((float) $row['total_earned'], 2);
                $totalPaid = round((float) $row['total_paid'], 2);
                $fraisDepenses = round((float) $row['frais_depenses'], 2);
                $balance = (float) max(0, round($totalEarned - (float) $row['en_attente'] - $fraisDepenses - $totalPaid, 2));

                return new VehiculeEarningsRow(
                    vehiculeId: (string) $row['vehicule_id'],
                    nomVehicule: (string) $row['nom_vehicule'],
                    immatriculation: (string) $row['immatriculation'],
                    fraisDepenses: $fraisDepenses,
                    totalEarned: $totalEarned,
                    totalPaid: $totalPaid,
                    balance: $balance,
                    liens: $row['liens'],
                    parActivite: collect(ActiviteCompte::cases())
                        ->filter(fn (ActiviteCompte $a) => isset($row['par_activite'][$a->value]))
                        ->map(fn (ActiviteCompte $a) => ['activite' => $a->value, 'total_earned' => round((float) $row['par_activite'][$a->value], 2)])
                        ->values()
                        ->all(),
                );
            })
            ->sortByDesc('totalEarned')
            ->values()
            ->all();
    }

    /**
     * Dépenses validées par véhicule — UNIQUEMENT pour un proprietaire (jamais
     * de frais imputés à un livreur dans ce moteur : comportement préservé tel
     * quel, un livreur sans profil proprietaire associé obtient toujours un
     * tableau vide ici, y compris pour ses propres véhicules d'équipe).
     *
     * @return array<string, float> vehicule_id => frais total approuvé
     */
    public function fraisDepensesParVehicule(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?array $vehiculeIds = null
    ): array {
        if ($organizationId === null || $proprietaire === null) {
            return [];
        }

        $vehiculeIdsOwner = Vehicule::where('proprietaire_id', $proprietaire->id)
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();
        $vehiculeIdsCibles = $vehiculeIdsOwner;

        if ($vehiculeIds !== null) {
            $vehiculeIdsCibles = $vehiculeIdsOwner->intersect($vehiculeIds)->values();
        }

        if ($vehiculeIdsCibles->isEmpty()) {
            return [];
        }

        return Depense::where('beneficiaire_type', 'vehicule')
            ->whereIn('beneficiaire_id', $vehiculeIdsCibles->all())
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $organizationId)
            ->when($dateDebut, fn ($q) => $q->whereDate('date_depense', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->whereDate('date_depense', '<=', $dateFin))
            ->selectRaw('beneficiaire_id, SUM(montant) as total')
            ->groupBy('beneficiaire_id')
            ->pluck('total', 'beneficiaire_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();
    }

    /**
     * Relevé chronologique, 100 dernières opérations. Les parts annulées y restent, avec leur
     * statut, sans compter dans aucun montant.
     *
     * @param  Collection<int, CommissionEnveloppePart>  $partsVentes  Parts, annulées comprises.
     * @param  Collection<int, CommissionLogistiquePart>  $partsLogistiques
     */
    public function releve(Collection $partsVentes, Collection $partsLogistiques): array
    {
        $lignesVentes = $partsVentes->map(function (CommissionEnveloppePart $part) {
            $operation = $part->enveloppe?->source;
            $vehicule = $operation?->vehicule;
            $date = $operation?->validated_at ?? $operation?->created_at ?? $part->created_at;
            $activite = ActiviteCompte::depuisBeneficiaireType($part->beneficiaire_type);

            return [
                'id' => 'vente-'.$part->id,
                'source' => $part->enveloppe?->source_type === TransfertLogistique::class ? 'Logistique' : 'Vente',
                'activite' => $activite?->value,
                'activite_label' => $activite?->label(),
                'reference' => $operation?->reference ?? '-',
                'vehicule_id' => $vehicule?->id,
                'vehicule_nom' => $vehicule?->nom_vehicule ?? '-',
                'immatriculation' => $vehicule?->immatriculation,
                'date_label' => $date?->format('d/m/Y'),
                'date_sort' => $date?->timestamp ?? 0,
                'frais' => 0.0,
                'montant_net' => (float) $part->montant_net,
                'montant_a_payer' => $part->montant_a_payer,
                'montant_verse' => (float) $part->montant_verse,
                'montant_restant' => $part->montant_restant,
                'statut' => $part->statut?->value ?? (string) $part->getRawOriginal('statut'),
                'statut_label' => $part->statut?->label(),
            ];
        });

        $lignesLogistiques = $partsLogistiques->map(function (CommissionLogistiquePart $part) {
            $transfert = $part->commission?->transfert;
            $vehicule = $part->commission?->vehicule;
            $date = $part->earned_at ?? $transfert?->date_arrivee_reelle ?? $transfert?->created_at ?? $part->created_at;
            $activite = ActiviteCompte::tryFrom((string) $part->type_beneficiaire);

            return [
                'id' => 'log-'.$part->id,
                'source' => 'Logistique',
                'activite' => $activite?->value,
                'activite_label' => $activite?->label(),
                'reference' => $transfert?->reference ?? '-',
                'vehicule_id' => $vehicule?->id,
                'vehicule_nom' => $vehicule?->nom_vehicule ?? '-',
                'immatriculation' => $vehicule?->immatriculation,
                'date_label' => $date?->format('d/m/Y'),
                'date_sort' => $date?->timestamp ?? 0,
                'frais' => (float) $part->frais_supplementaires,
                'montant_net' => (float) $part->montant_net,
                'montant_a_payer' => $part->montant_a_payer,
                'montant_verse' => (float) $part->montant_verse,
                'montant_restant' => (float) $part->montant_restant,
                'statut' => $part->statut?->value ?? (string) $part->getRawOriginal('statut'),
                'statut_label' => $part->statut_label,
            ];
        });

        return $lignesVentes
            ->concat($lignesLogistiques)
            ->sortByDesc('date_sort')
            ->values()
            ->take(100)
            ->map(function (array $row) {
                unset($row['date_sort']);

                return $row;
            })
            ->all();
    }

    /**
     * Véhicules sur lesquels le compte a au moins une part, toutes périodes : un véhicule quitté ou
     * vu au travers d'une commission de consultant reste filtrable (seulement sur ses propres parts).
     *
     * @return Collection<int, Vehicule>
     */
    public function vehiculesDesParts(BeneficiairesCompte $beneficiaires): Collection
    {
        if ($beneficiaires->estVide()) {
            return collect();
        }

        $enveloppes = CommissionEnveloppe::query()->whereIn('id', $this->partsQuery($beneficiaires)->select('enveloppe_id'));
        $vehiculeIds = collect([CommandeVente::class, TransfertLogistique::class])
            ->flatMap(fn (string $source) => $source::query()
                ->whereIn('id', (clone $enveloppes)->where('source_type', $source)->select('source_id'))
                ->whereNotNull('vehicule_id')
                ->distinct()
                ->pluck('vehicule_id'))
            ->unique()
            ->values();

        return Vehicule::query()
            ->where('organization_id', $beneficiaires->organizationId)
            ->whereIn('id', $vehiculeIds)
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation']);
    }

    /**
     * @param  Collection<int, CommissionEnveloppePart>  $parts
     * @return Collection<int, CommissionEnveloppePart>
     */
    private function sansAnnulees(Collection $parts): Collection
    {
        return $parts->reject(fn (CommissionEnveloppePart $p) => $p->statut === StatutCommission::ANNULEE)->values();
    }

    /**
     * Véhicules accessibles à une identité (proprietaire et/ou livreur) — même
     * requête que celle déjà utilisée par `ClientDashboardPayloadBuilder` et
     * VehiculesController (API), centralisée ici. `$with` reste au choix de
     * l'appelant : les deux appelants existants ont des besoins d'eager-load
     * différents (capacités vs équipe). Ouvre les écrans opérationnels (ventes,
     * frais) : un véhicule seulement parrainé n'en fait jamais partie (ADR 0054,
     * cf. VehiculesRattachesService pour la liste complète des rattachements).
     *
     * @return Collection<int, Vehicule>
     */
    public function vehiculesAccessibles(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        array $with = []
    ): Collection {
        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return Vehicule::query()
            ->when($with !== [], fn ($q) => $q->with($with))
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere('proprietaire_id', $proprietaire->id);
                }
                if ($livreur !== null) {
                    $query->orWhereHas('equipe.membres', fn ($sq) => $sq->where('livreur_id', $livreur->id));
                }
            })
            ->orderBy('nom_vehicule')
            ->get();
    }

    /**
     * Filtres de période partagés entre le dashboard Inertia (`client/Dashboard`)
     * et l'API mobile (`GET /v1/mobile/dashboard`) — un seul et même calcul de
     * dates, pour que les deux surfaces ne puissent jamais afficher des plages
     * différentes pour un même raccourci de période. Extrait tel quel de
     * `ClientDashboardController::resolveDashboardFilters()`.
     */
    /**
     * Règles extraites en méthode statique réutilisable (comme
     * VehicleProposalService::validationRules()) — consommées à la fois par le
     * `$request->validate()` ci-dessous et par `DashboardMineRequest::rules()`
     * (API), qui rend ces filtres visibles dans la doc OpenAPI générée sans
     * dupliquer la liste de règles.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function filterValidationRules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['7j', '30j', 'ce_mois', 'mois_passe', 'custom'])],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'vehicule_id' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(array_map(fn (StatutCommission $s) => $s->value, StatutCommission::cases()))],
        ];
    }

    public function resolveFilters(Request $request): array
    {
        $validated = $request->validate(self::filterValidationRules());

        $period = $validated['period'] ?? 'ce_mois';
        $dateDebut = $validated['date_debut'] ?? null;
        $dateFin = $validated['date_fin'] ?? null;

        if ($period !== 'custom') {
            [$dateDebut, $dateFin] = $this->periodToDates($period);
        }

        return [
            'period' => $period,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'vehicule_id' => $validated['vehicule_id'] ?? null,
            'statut' => $validated['statut'] ?? null,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function periodToDates(string $period): array
    {
        $today = Carbon::today();

        return match ($period) {
            '7j' => [
                $today->copy()->subDays(6)->toDateString(),
                $today->toDateString(),
            ],
            'ce_mois' => [
                $today->copy()->startOfMonth()->toDateString(),
                $today->toDateString(),
            ],
            'mois_passe' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [
                $today->copy()->subDays(29)->toDateString(),
                $today->toDateString(),
            ],
        };
    }
}
