<?php

namespace App\Services\Comptes;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\Parrain;
use App\Models\TransfertLogistique;
use App\Services\CommissionVenteCalculatorService;
use App\Support\Commission\CommissionKpiBuckets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Lecture des commissions d'un parrain pour son espace (ADR 0050). Aucun calcul propre : les
 * montants viennent de CommissionKpiBuckets et de calculerResume(), comme les écrans de commissions
 * du back-office. Toute requête part du bénéficiaire : seules ses parts sont lues, jamais celles de
 * l'équipe, du propriétaire ou du site sur la même opération.
 */
class EspaceParrainService
{
    /** Types d'opérations qui rémunèrent un parrain (PARRAIN-008). */
    private const SOURCES = [CommandeVente::class, TransfertLogistique::class];

    /** Une commission annulée n'a jamais représenté de créance réelle (cf. CommissionKpiBuckets). */
    public function parts(Parrain $parrain): Builder
    {
        return CommissionEnveloppePart::query()
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)
            ->where('beneficiaire_id', $parrain->id)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', fn (Builder $q) => $q->where('organization_id', $parrain->organization_id));
    }

    /**
     * @param  array{vehicule_id: ?string, statut: ?string, date_debut: ?string, date_fin: ?string}  $filtres
     */
    public function partsFiltrees(Parrain $parrain, array $filtres): Builder
    {
        return $this->parts($parrain)
            ->when($filtres['statut'], fn (Builder $q, string $statut) => $q->where('statut', $statut))
            ->when($filtres['date_debut'], fn (Builder $q, string $date) => $q
                ->whereHas('enveloppe', fn (Builder $e) => $e->whereDate('earned_at', '>=', $date)))
            ->when($filtres['date_fin'], fn (Builder $q, string $date) => $q
                ->whereHas('enveloppe', fn (Builder $e) => $e->whereDate('earned_at', '<=', $date)))
            ->when($filtres['vehicule_id'], fn (Builder $q, string $vehiculeId) => $q
                ->whereHas('enveloppe', fn (Builder $e) => $e
                    ->whereHasMorph('source', self::SOURCES, fn (Builder $s) => $s->where('vehicule_id', $vehiculeId))));
    }

    /** Parts paginables, de la plus récente à la plus ancienne, avec leur opération et son véhicule. */
    public function pourListe(Builder $parts): Builder
    {
        return $parts
            ->with(['enveloppe' => fn ($q) => $q->with([
                'processus',
                'source' => fn (MorphTo $source) => $source->morphWith([
                    CommandeVente::class => ['vehicule'],
                    TransfertLogistique::class => ['vehicule'],
                ]),
            ])])
            ->orderByDesc(CommissionEnveloppe::query()
                ->select('earned_at')
                ->whereColumn('commission_enveloppes.id', 'commission_enveloppe_parts.enveloppe_id'))
            ->orderByDesc('id');
    }

    /**
     * total_genere = en_attente_periode + payable + deja_paye. reste_a_payer ne porte que sur les parts
     * passées par la validation de leur période, comme au back-office (décision du 20/08/2026).
     *
     * @return array{total_genere: float, en_attente_periode: float, payable: float, deja_paye: float, reste_a_payer: float}
     */
    public function totaux(Builder $parts): array
    {
        $lignes = (clone $parts)->get();
        $actives = $lignes->filter(fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE);

        return [
            ...CommissionKpiBuckets::calculer($lignes),
            'reste_a_payer' => (float) CommissionVenteCalculatorService::calculerResume(
                (float) $actives->sum('montant_brut'),
                0.0,
                (float) $actives->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
                0.0,
                (float) $actives->sum('montant_verse'),
            )['reste'],
        ];
    }
}
