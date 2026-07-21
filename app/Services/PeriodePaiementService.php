<?php

namespace App\Services;

use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\PaiementPeriode;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Cycle de paiement automatique : les périodes ne sont plus créées manuellement, elles
 * sont générées à la volée dès qu'elles sont nécessaires (consultation, calcul des
 * commissions, génération des fiches), ou en avance par la commande artisan
 * `periodes:generer-manquantes`.
 *
 * Tous les types de bénéficiaires (livreur, propriétaire, salarié) partagent désormais
 * le même cycle de quinzaine :
 *   P1 : du 1er au 15 du mois inclus
 *   P2 : du 16 au dernier jour du mois
 *
 * Référence : "PAY-{AAAAMM}-{P1|P2}-{LIV|PRO|SAL}", ex. "PAY-202607-P1-LIV".
 */
class PeriodePaiementService
{
    public const P1 = 'P1';

    public const P2 = 'P2';

    // ── Calcul pur (aucun accès base de données) ─────────────────────────────────

    public static function quinzaineForDate(Carbon $date): string
    {
        return $date->day <= 15 ? self::P1 : self::P2;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function dateRangeFor(int $year, int $month, string $quinzaine): array
    {
        if (! in_array($quinzaine, [self::P1, self::P2], true)) {
            throw new InvalidArgumentException("Quinzaine invalide : {$quinzaine}");
        }

        if ($quinzaine === self::P1) {
            return [
                Carbon::create($year, $month, 1)->startOfDay(),
                Carbon::create($year, $month, 15)->endOfDay(),
            ];
        }

        return [
            Carbon::create($year, $month, 16)->startOfDay(),
            Carbon::create($year, $month)->endOfMonth()->endOfDay(),
        ];
    }

    public static function referenceFor(TypePeriodePaiement $type, int $year, int $month, string $quinzaine): string
    {
        return sprintf('PAY-%04d%02d-%s-%s', $year, $month, $quinzaine, $type->abreviation());
    }

    public static function labelFor(int $year, int $month, string $quinzaine): string
    {
        $moisLabel = ucfirst(Carbon::create($year, $month, 1)->locale('fr')->translatedFormat('F Y'));

        return "{$moisLabel} - {$quinzaine}";
    }

    // ── Lecture / création ────────────────────────────────────────────────────────

    public function getPeriodByDate(string $organizationId, TypePeriodePaiement $type, Carbon $date): ?PaiementPeriode
    {
        $quinzaine = self::quinzaineForDate($date);
        [$debut] = self::dateRangeFor($date->year, $date->month, $quinzaine);

        return PaiementPeriode::where('organization_id', $organizationId)
            ->where('type', $type->value)
            ->whereDate('date_debut', $debut->toDateString())
            ->first();
    }

    /** Clé de regroupement (date de début de quinzaine) pour une date donnée. */
    public static function debutKeyForDate(Carbon $date): string
    {
        $quinzaine = self::quinzaineForDate($date);

        return self::dateRangeFor($date->year, $date->month, $quinzaine)[0]->toDateString();
    }

    /**
     * Résout en une seule requête les `PaiementPeriode` couvrant un lot de dates —
     * évite le N+1 de `getPeriodByDate()` appelée en boucle sur une liste de bénéficiaires.
     *
     * @param  iterable<mixed>  $dates
     * @return Collection<string, PaiementPeriode> indexée par `debutKeyForDate()`
     */
    public function getPeriodsForDates(string $organizationId, TypePeriodePaiement $type, iterable $dates): Collection
    {
        $debuts = collect($dates)
            ->filter()
            ->map(fn ($d) => self::debutKeyForDate(Carbon::parse($d)))
            ->unique()
            ->values();

        if ($debuts->isEmpty()) {
            return collect();
        }

        // whereIn() compare la colonne par égalité stricte, mais date_debut est
        // stocké avec un suffixe horaire ("2026-07-01 00:00:00") — whereDate()
        // tronque l'heure côté SQL, comme le fait déjà getPeriodByDate().
        return PaiementPeriode::where('organization_id', $organizationId)
            ->where('type', $type->value)
            ->where(function ($query) use ($debuts) {
                foreach ($debuts as $debut) {
                    $query->orWhereDate('date_debut', $debut);
                }
            })
            ->get()
            ->keyBy(fn (PaiementPeriode $p) => $p->date_debut->toDateString());
    }

    public function getOrCreatePeriod(string $organizationId, TypePeriodePaiement $type, Carbon $date, ?string $createdBy = null): PaiementPeriode
    {
        $quinzaine = self::quinzaineForDate($date);
        [$debut, $fin] = self::dateRangeFor($date->year, $date->month, $quinzaine);

        return PaiementPeriode::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'reference' => self::referenceFor($type, $date->year, $date->month, $quinzaine),
            ],
            [
                'type' => $type->value,
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin->toDateString(),
                'statut' => StatutPeriodePaiement::BROUILLON->value,
                'created_by' => $createdBy,
            ],
        );
    }

    public function getCurrentPeriod(string $organizationId, TypePeriodePaiement $type, ?string $createdBy = null): PaiementPeriode
    {
        return $this->getOrCreatePeriod($organizationId, $type, Carbon::now(), $createdBy);
    }

    public function getNextPeriod(string $organizationId, TypePeriodePaiement $type, ?string $createdBy = null): PaiementPeriode
    {
        $courante = $this->getCurrentPeriod($organizationId, $type, $createdBy);
        $dateSuivante = Carbon::parse($courante->date_fin)->addDay();

        return $this->getOrCreatePeriod($organizationId, $type, $dateSuivante, $createdBy);
    }

    /**
     * Génère (de façon idempotente) toutes les périodes P1/P2 de l'année pour les 3
     * types de bénéficiaires. Utilisé par la commande artisan de rattrapage.
     *
     * @return Collection<int, PaiementPeriode>
     */
    public function generatePeriodsForYear(string $organizationId, int $year, ?string $createdBy = null): Collection
    {
        $periodes = collect();

        foreach (TypePeriodePaiement::cases() as $type) {
            for ($month = 1; $month <= 12; $month++) {
                foreach ([self::P1, self::P2] as $quinzaine) {
                    [$debut] = self::dateRangeFor($year, $month, $quinzaine);
                    $periodes->push($this->getOrCreatePeriod($organizationId, $type, $debut, $createdBy));
                }
            }
        }

        return $periodes;
    }
}
