<?php

namespace App\Services;

use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\PaiementPeriode;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Point unique de vérité pour la règle métier : "cette période/cette part est-elle
 * payable ?". Ne fait aucune vérification d'autorisation (organisation, permission) —
 * ça reste le rôle des Policies. Vérifie uniquement le statut métier de la période, pour
 * qu'un paiement ne puisse jamais être enregistré tant que la période qui le couvre n'a
 * pas été validée.
 */
class PeriodePayabilityChecker
{
    // ── Cas 1 : période liée par FK directe (PaiementFiche, PaieLigne) ────────────

    public static function assertPeriodePayable(PaiementPeriode|PaiePeriode $periode): void
    {
        if (! $periode->peutEtrePayee()) {
            throw new InvalidArgumentException(self::messagePeriodeNonPayable($periode));
        }
    }

    // ── Cas 2 : période à résoudre par date (CommissionLogistiquePart / CommissionPart) ──

    public static function periodeForCommissionPart(CommissionLogistiquePart|CommissionPart $part): ?PaiementPeriode
    {
        $organizationId = $part->commission->organization_id;
        $type = TypePeriodePaiement::from($part->type_beneficiaire);
        $date = $part instanceof CommissionLogistiquePart
            ? Carbon::parse($part->earned_at)
            : Carbon::parse($part->commission->created_at);

        return app(PeriodePaiementService::class)->getPeriodByDate($organizationId, $type, $date);
    }

    public static function reasonPartNotPayable(CommissionLogistiquePart|CommissionPart $part): ?string
    {
        $periode = self::periodeForCommissionPart($part);

        if ($periode === null) {
            return "La période de paiement correspondant à cette commission n'a pas encore été calculée. Le paiement ne peut pas être effectué.";
        }

        if (! $periode->peutEtrePayee()) {
            return self::messagePeriodeNonPayable($periode);
        }

        return null;
    }

    public static function assertPartPayable(CommissionLogistiquePart|CommissionPart $part): void
    {
        if ($reason = self::reasonPartNotPayable($part)) {
            throw new InvalidArgumentException($reason);
        }
    }

    /** @param  Collection<int, CommissionLogistiquePart|CommissionPart>  $parts */
    public static function assertPartsPayable(Collection $parts): void
    {
        foreach ($parts as $part) {
            self::assertPartPayable($part);
        }
    }

    /** @param  Collection<int, PaieLigne>  $lignes */
    public static function assertLignesPayables(Collection $lignes): void
    {
        foreach ($lignes as $ligne) {
            self::assertPeriodePayable($ligne->periode);
        }
    }

    // ── Utilitaire FIFO : ne retenir que les items réellement consommés ───────────

    /**
     * Un paiement groupé (FIFO) peut consommer plusieurs parts/lignes ayant des dates
     * différentes, donc potentiellement plusieurs périodes distinctes. On ne doit
     * vérifier que celles réellement touchées par le montant demandé — pas tout le
     * stock disponible du bénéficiaire.
     *
     * @param  Collection<int, mixed>  $items  déjà triés FIFO (plus ancien en premier)
     * @param  Closure(mixed): float  $remainingOf  reste dû pour un item
     * @return Collection<int, mixed>
     */
    public static function touchedUntilAmount(Collection $items, float $montant, Closure $remainingOf): Collection
    {
        $touched = collect();
        $restant = $montant;

        foreach ($items as $item) {
            if ($restant <= 0.009) {
                break;
            }

            $reste = $remainingOf($item);
            if ($reste <= 0) {
                continue;
            }

            $touched->push($item);
            $restant -= min($restant, $reste);
        }

        return $touched;
    }

    // ── Affichage : statut effectif + payabilité pour un badge front ──────────────

    /**
     * Dérive le statut à afficher (clé compatible `StatusDot`) et la payabilité
     * d'une ligne bénéficiaire, à partir de sa période (déjà résolue par l'appelant,
     * batché via `PeriodePaiementService::getPeriodsForDates()`) et de son statut de
     * paiement brut (ex: `impaye`/`partiel`/`paye` ou `a_payer`/`partiellement_paye`/`paye`).
     *
     * @return array{status: string, label: string, payable: bool}
     */
    public static function statutAffichage(?PaiementPeriode $periode, string $statutPaiementValue, string $statutPaiementLabel): array
    {
        if ($statutPaiementValue === 'paye') {
            return ['status' => 'paye', 'label' => $statutPaiementLabel, 'payable' => false];
        }

        if ($periode === null || $periode->isBrouillon()) {
            return ['status' => 'brouillon', 'label' => 'En attente', 'payable' => false];
        }

        if ($periode->isCalculee()) {
            return ['status' => 'calculee', 'label' => 'En attente de validation', 'payable' => false];
        }

        if ($periode->isCloturee()) {
            return ['status' => 'cloturee', 'label' => 'Clôturée', 'payable' => false];
        }

        return ['status' => $statutPaiementValue, 'label' => $statutPaiementLabel, 'payable' => true];
    }

    private static function messagePeriodeNonPayable(PaiementPeriode|PaiePeriode $periode): string
    {
        $label = $periode instanceof PaiementPeriode
            ? ($periode->reference ?: $periode->statut_label)
            : $periode->labelPeriode();

        return "La période {$label} n'est pas validée (statut actuel : {$periode->statut->label()}). Le paiement est refusé.";
    }
}
