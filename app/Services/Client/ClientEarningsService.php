<?php

namespace App\Services\Client;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Moteur financier unique de l'espace client (gains, dépenses, solde) — SOURCE
 * DE VÉRITÉ PARTAGÉE entre l'espace client Inertia (ClientDashboardController)
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
 */
class ClientEarningsService
{
    /**
     * @param  Collection<int, Vehicule>  $vehicules  Véhicules déjà résolus (et déjà vérifiés
     *                                                comme accessibles) par l'appelant.
     * @param  array<string>|null  $vehiculeIds  Restreint aux véhicules donnés (filtre utilisateur) ;
     *                                           `null` = tous les véhicules accessibles.
     */
    public function summary(
        Collection $vehicules,
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null,
    ): array {
        $partsVentes = $this->partsVentes($organizationId, $proprietaire, $livreur, $dateDebut, $dateFin, $statut, $vehiculeIds);
        $partsLogistiques = $this->partsLogistiques($organizationId, $proprietaire, $livreur, $dateDebut, $dateFin, $statut, $vehiculeIds);
        $fraisParVehicule = $this->fraisDepensesParVehicule($organizationId, $proprietaire, $dateDebut, $dateFin, $vehiculeIds);
        $fraisTotal = (float) array_sum($fraisParVehicule);

        return [
            'totals' => $this->calculateEarnings($partsVentes, $partsLogistiques, $fraisTotal),
            'by_vehicule' => $this->earningsByVehicule($vehicules, $partsVentes, $partsLogistiques, $fraisParVehicule),
            'statement' => $this->releve($partsVentes, $partsLogistiques),
        ];
    }

    /**
     * @return Collection<int, CommissionEnveloppePart>
     */
    public function partsVentes(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null
    ): Collection {
        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return CommissionEnveloppePart::query()
            ->with([
                // vehicule_id doit être sélectionné ici : c'est la clé étrangère utilisée
                // par la relation `vehicule()` sur `source` juste en dessous — sans elle,
                // Eloquent charge un `source` sans FK et `source->vehicule` résout
                // silencieusement à null (bug distinct découvert le 26/08/2026 en écrivant
                // le test de non-régression de earningsByVehicule()).
                'enveloppe.source:id,reference,validated_at,created_at,vehicule_id',
                'enveloppe.source.vehicule:id,nom_vehicule,immatriculation',
            ])
            ->whereHas('enveloppe', fn ($query) => $query->where('organization_id', $organizationId))
            // tous les statuts sont actifs (creee/impaye/partiel/paye)
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
            ->where(function ($query) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $query->orWhere(function ($sq) use ($proprietaire) {
                        $sq->where('beneficiaire_type', 'proprietaire')
                            ->where('beneficiaire_id', $proprietaire->id);
                    });
                }

                if ($livreur !== null) {
                    $query->orWhere(function ($sq) use ($livreur) {
                        $sq->where('beneficiaire_type', 'livreur')
                            ->where('beneficiaire_id', $livreur->id);
                    });
                }
            })
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, CommissionLogistiquePart>
     */
    public function partsLogistiques(
        ?string $organizationId,
        ?Proprietaire $proprietaire,
        ?Livreur $livreur,
        ?string $dateDebut = null,
        ?string $dateFin = null,
        ?string $statut = null,
        ?array $vehiculeIds = null
    ): Collection {
        if ($organizationId === null || ($proprietaire === null && $livreur === null)) {
            return collect();
        }

        return CommissionLogistiquePart::query()
            ->with([
                'commission.transfert:id,reference,date_arrivee_reelle,created_at',
                'commission.vehicule:id,nom_vehicule,immatriculation',
            ])
            ->whereHas('commission', fn ($query) => $query->where('organization_id', $organizationId))
            // tous les statuts sont actifs (impaye/partiel/paye)
            ->when($dateDebut, fn ($q) => $q->whereDate('created_at', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->whereDate('created_at', '<=', $dateFin))
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
     * Totaux agrégés (vente + logistique − dépenses). `balance` ne descend
     * jamais sous 0 (comportement préservé tel quel : un solde négatif n'est
     * jamais affiché comme dette du côté propriétaire dans ce moteur).
     */
    public function calculateEarnings(Collection $partsVentes, Collection $partsLogistiques, float $fraisDepensesTotal = 0.0): array
    {
        $totalEarned = round(
            (float) $partsVentes->sum('montant_a_payer') + (float) $partsLogistiques->sum('montant_a_payer'),
            2
        );
        $totalPaid = round(
            (float) $partsVentes->sum('montant_verse') + (float) $partsLogistiques->sum('montant_verse'),
            2
        );
        $frais = round($fraisDepensesTotal, 2);

        return [
            'total_earned' => $totalEarned,
            'total_paid' => $totalPaid,
            'frais_depenses_total' => $frais,
            'balance' => max(0, round($totalEarned - $frais - $totalPaid, 2)),
            'operations_count' => $partsVentes->count() + $partsLogistiques->count(),
        ];
    }

    /**
     * @param  Collection<int, Vehicule>  $vehicules
     * @param  Collection<int, CommissionEnveloppePart>  $partsVentes
     * @param  Collection<int, CommissionLogistiquePart>  $partsLogistiques
     * @param  array<string, float>  $fraisParVehicule
     */
    public function earningsByVehicule(Collection $vehicules, Collection $partsVentes, Collection $partsLogistiques, array $fraisParVehicule = []): array
    {
        $stats = [];

        foreach ($vehicules as $vehicule) {
            $stats[$vehicule->id] = [
                'vehicule_id' => $vehicule->id,
                'nom_vehicule' => $vehicule->nom_vehicule,
                'immatriculation' => $vehicule->immatriculation,
                'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                'total_earned' => 0.0,
                'total_paid' => 0.0,
                'balance' => 0.0,
            ];
        }

        foreach ($partsVentes as $part) {
            // Correctif du 26/08/2026 : CommissionEnveloppePart n'a pas de relation
            // `commission` (seulement `enveloppe`) — cf. docblock de la classe.
            $vehicule = $part->enveloppe?->source?->vehicule;
            if ($vehicule === null) {
                continue;
            }
            if (! isset($stats[$vehicule->id])) {
                $stats[$vehicule->id] = [
                    'vehicule_id' => $vehicule->id,
                    'nom_vehicule' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                    'total_earned' => 0.0,
                    'total_paid' => 0.0,
                    'balance' => 0.0,
                ];
            }
            $stats[$vehicule->id]['total_earned'] += $part->montant_a_payer;
            $stats[$vehicule->id]['total_paid'] += (float) $part->montant_verse;
        }

        foreach ($partsLogistiques as $part) {
            $vehicule = $part->commission?->vehicule;
            if ($vehicule === null) {
                continue;
            }
            if (! isset($stats[$vehicule->id])) {
                $stats[$vehicule->id] = [
                    'vehicule_id' => $vehicule->id,
                    'nom_vehicule' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'frais_depenses' => (float) ($fraisParVehicule[$vehicule->id] ?? 0.0),
                    'total_earned' => 0.0,
                    'total_paid' => 0.0,
                    'balance' => 0.0,
                ];
            }
            $stats[$vehicule->id]['total_earned'] += $part->montant_a_payer;
            $stats[$vehicule->id]['total_paid'] += (float) $part->montant_verse;
        }

        return collect($stats)
            ->map(function (array $row) {
                $row['total_earned'] = round((float) $row['total_earned'], 2);
                $row['total_paid'] = round((float) $row['total_paid'], 2);
                $row['frais_depenses'] = round((float) $row['frais_depenses'], 2);
                $row['balance'] = max(0, round($row['total_earned'] - $row['frais_depenses'] - $row['total_paid'], 2));

                return $row;
            })
            ->sortByDesc('total_earned')
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
     * Relevé chronologique (vente + logistique), 100 dernières opérations.
     *
     * @param  Collection<int, CommissionEnveloppePart>  $partsVentes
     * @param  Collection<int, CommissionLogistiquePart>  $partsLogistiques
     */
    public function releve(Collection $partsVentes, Collection $partsLogistiques): array
    {
        $lignesVentes = $partsVentes->map(function (CommissionEnveloppePart $part) {
            $commande = $part->enveloppe?->source;
            $vehicule = $commande?->vehicule;
            $date = $commande?->validated_at ?? $commande?->created_at ?? $part->created_at;

            return [
                'id' => 'vente-'.$part->id,
                'source' => 'Vente',
                'reference' => $commande?->reference ?? '-',
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

            return [
                'id' => 'log-'.$part->id,
                'source' => 'Logistique',
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
     * Véhicules accessibles à une identité (proprietaire et/ou livreur) — même
     * requête que celle déjà utilisée par ClientDashboardController et
     * VehiculesController (API), centralisée ici car `summary()` en a besoin
     * en entrée. `$with` reste au choix de l'appelant : les deux contrôleurs
     * existants ont des besoins d'eager-load différents (capacités vs équipe).
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
    public function resolveFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7j', '30j', 'ce_mois', 'mois_passe', 'custom'])],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'vehicule_id' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in(array_map(fn (StatutCommission $s) => $s->value, StatutCommission::cases()))],
        ]);

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
