<?php

namespace App\Services\Commission;

use App\Enums\StatutCommission;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionEnveloppePart;
use App\Models\PaiementPeriode;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rattrapage des fiches de paiement des parrains (PARRAIN-017) : avant le correctif du 25/10/2026,
 * une commission de parrain générée alors que sa période « Parrains » n'existait pas encore ne
 * figurait sur aucune fiche. Ce service retrouve ces parts — non annulées, non payées, absentes de
 * toute fiche — et les fait entrer sur leur fiche par le circuit normal des périodes : ouverture de
 * la période si elle manque (PeriodePaiementService, unique par organisation et référence), puis
 * recalcul, réouverture d'une période validée et revalidation (PeriodeCalculatorService, en mode
 * strict). Rien n'est régénéré : ni vente, ni commission ; les fiches payées sont figées (ADR 0010)
 * et une période clôturée n'est jamais touchée.
 */
class RattrapageFichesParrainService
{
    public const A_REGULARISER = 'a_regulariser';

    public const CLOTUREE = 'periode_cloturee';

    public const REGULARISEE = 'regularisee';

    public const ERREUR = 'erreur';

    public function __construct(
        private readonly PeriodePaiementService $periodes,
        private readonly PeriodeCalculatorService $calculateur,
    ) {}

    /**
     * Parts de parrain sans fiche, regroupées par organisation et période. Lecture seule.
     *
     * @return Collection<int, array{organization_id: string, reference: string, date: string, periode_existe: bool, statut_periode: ?string, parts: int, montant: float, action: string}>
     */
    public function analyser(?string $organizationId = null): Collection
    {
        $parts = CommissionEnveloppePart::query()
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)
            ->whereNotIn('statut', [StatutCommission::ANNULEE->value, StatutCommission::PAYE->value])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('paiement_fiche_lignes')
                ->join('paiement_fiches', 'paiement_fiches.id', '=', 'paiement_fiche_lignes.fiche_id')
                ->whereNull('paiement_fiches.deleted_at')
                ->where('paiement_fiche_lignes.source_type', CommissionEnveloppePart::class)
                ->whereColumn('paiement_fiche_lignes.source_id', 'commission_enveloppe_parts.id'))
            ->whereHas('enveloppe', fn ($q) => $q->when($organizationId, fn ($w) => $w->where('organization_id', $organizationId)))
            ->with('enveloppe:id,organization_id,earned_at')
            ->get();

        return $parts
            ->groupBy(function (CommissionEnveloppePart $p) {
                return $p->enveloppe->organization_id.'|'.self::reference(Carbon::parse($p->enveloppe->earned_at));
            })
            ->map(function (Collection $groupe) {
                $enveloppe = $groupe->first()->enveloppe;
                $date = Carbon::parse($enveloppe->earned_at);
                $reference = self::reference($date);
                $periode = PaiementPeriode::where('organization_id', $enveloppe->organization_id)->where('reference', $reference)->first();

                return [
                    'organization_id' => $enveloppe->organization_id,
                    'reference' => $reference,
                    'date' => $date->toDateString(),
                    'periode_existe' => $periode !== null,
                    'statut_periode' => $periode?->statut?->value,
                    'parts' => $groupe->count(),
                    'montant' => round((float) $groupe->sum(fn (CommissionEnveloppePart $p) => (float) $p->montant_a_payer), 2),
                    'action' => $periode?->isCloturee() ? self::CLOTUREE : self::A_REGULARISER,
                ];
            })
            ->sortBy(fn (array $g) => $g['organization_id'].$g['date'])
            ->values();
    }

    /**
     * Fait entrer les parts sur leur fiche, période par période, chacune dans sa transaction : un
     * échec annule sa période seulement et est rapporté. Idempotent : une seconde exécution ne
     * trouve plus rien à régulariser.
     *
     * @param  Collection<int, array<string, mixed>>  $groupes  résultat de analyser()
     * @return Collection<int, array<string, mixed>> les mêmes groupes, `action` = résultat
     */
    public function appliquer(Collection $groupes): Collection
    {
        return $groupes->map(function (array $groupe) {
            if ($groupe['action'] !== self::A_REGULARISER) {
                return $groupe;
            }

            try {
                DB::transaction(function () use ($groupe) {
                    $this->periodes->getOrCreatePeriod($groupe['organization_id'], TypePeriodePaiement::PARRAIN, Carbon::parse($groupe['date']));
                    $this->calculateur->traiterPeriodesPourDates($groupe['organization_id'], collect([$groupe['date']]), strict: true);
                });
            } catch (Throwable $e) {
                return [...$groupe, 'action' => self::ERREUR, 'erreur' => $e->getMessage()];
            }

            // Contrôle : plus aucune part de ce groupe hors fiche.
            $restantes = $this->analyser($groupe['organization_id'])->firstWhere('reference', $groupe['reference']);

            return $restantes === null
                ? [...$groupe, 'action' => self::REGULARISEE]
                : [...$groupe, 'action' => self::ERREUR, 'erreur' => "{$restantes['parts']} part(s) toujours sans fiche après le calcul."];
        });
    }

    private static function reference(Carbon $date): string
    {
        return PeriodePaiementService::referenceFor(
            TypePeriodePaiement::PARRAIN,
            $date->year,
            $date->month,
            PeriodePaiementService::quinzaineForDate($date),
        );
    }
}
