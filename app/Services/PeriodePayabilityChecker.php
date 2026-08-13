<?php

namespace App\Services;

use App\Enums\TypePeriodePaiement;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\PaieLigne;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\PaiePeriode;
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

    // ── Verrou double paiement : fiche vs paiement direct ──────────────────────────

    /**
     * Empêche le paiement DIRECT (FIFO sur CommissionPart/CommissionLogistiquePart) d'une
     * part dont la période a déjà une PaiementFiche générée pour ce bénéficiaire.
     *
     * Raison : PaiementFiche.montant_paye ne dérive QUE de son historique de paiements
     * propre (PaiementFichePaiement) — un paiement direct ne le met jamais à jour. Une
     * fois qu'une fiche existe pour une période, c'est donc le SEUL canal sûr : sans ce
     * verrou, payer directement puis payer à nouveau via la fiche double-paierait le
     * bénéficiaire sans que rien ne le détecte (montant_net de la fiche resterait "dû").
     *
     * @param  Collection<int, CommissionLogistiquePart|CommissionPart>  $parts  déjà réduites aux items réellement touchés (cf. touchedUntilAmount)
     */
    public static function assertPartsNotClaimedByFiche(Collection $parts, string $type, string $beneficiaireId): void
    {
        $periodes = $parts
            ->map(fn ($part) => self::periodeForCommissionPart($part))
            ->filter()
            ->unique('id');

        foreach ($periodes as $periode) {
            $ficheExiste = PaiementFiche::query()
                ->where('periode_id', $periode->id)
                ->where('beneficiaire_type', $type)
                ->where('beneficiaire_id', $beneficiaireId)
                ->exists();

            if ($ficheExiste) {
                throw new InvalidArgumentException(
                    "Une fiche de paiement existe déjà pour la période {$periode->reference} de ce bénéficiaire. ".
                    'Le paiement direct est bloqué pour cette période — utilisez la fiche '.
                    '(Comptabilité > Fiches de paiement) pour éviter un double paiement.'
                );
            }
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

    private static function messagePeriodeNonPayable(PaiementPeriode|PaiePeriode $periode): string
    {
        $label = $periode instanceof PaiementPeriode
            ? ($periode->reference ?: $periode->statut_label)
            : $periode->labelPeriode();

        return "La période {$label} n'est pas validée (statut actuel : {$periode->statut->label()}). Le paiement est refusé.";
    }
}
