<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\CashbackVersement;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;

/**
 * Comptabilise la jambe trésorerie d'un versement de cashback
 * (CashbackVersement) — avant ce chantier, ce paiement n'avait AUCUNE écriture
 * dans compta_ecritures, uniquement une trace dans l'ancien JournalTresorerie
 * (audit du 2026-08-22, seul flux qui bloquait sa suppression).
 *
 * Volontairement minimal, même convention que PaieComptabilisationService :
 * pas d'engagement/dette préalable comptabilisé au moment où le cashback est
 * gagné (CashbackTransaction type=gain) — seul le décaissement réel est
 * tracé ici. Bloquant : si la pièce comptable échoue, le versement doit être
 * annulé (cf. CashbackService::verser(), déjà encapsulé dans une transaction).
 */
class CashbackComptabilisationService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    public function comptabiliserVersement(CashbackVersement $versement): ?PieceComptable
    {
        $transaction = $versement->transaction;
        if (! $transaction) {
            return null;
        }

        $lignes = [
            [
                'role' => 'charge_cashback',
                'sens' => 'debit',
                'montant' => (float) $versement->montant,
                'tiers_type' => 'client',
                'tiers_model' => $transaction->client,
            ],
            [
                'role' => 'tresorerie',
                'sens' => 'credit',
                'montant' => (float) $versement->montant,
                'moyen_paiement' => $versement->mode_paiement,
            ],
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::VERSEMENT_CASHBACK,
            source: $versement,
            organizationId: $transaction->organization_id,
            dateComptable: Carbon::parse($versement->date_versement ?? now()),
            libelle: 'Cashback versé — '.($transaction->client?->nom ?? '—'),
            lignes: $lignes,
            // Un cashback n'est jamais rattaché à un site précis (versement
            // centralisé au niveau organisation, cf. CashbackController) —
            // contrairement aux commissions/salaires payés localement.
            siteId: null,
            createdBy: $versement->created_by,
        );
    }
}
