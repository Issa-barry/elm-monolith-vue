<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\CommissionPayment;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Comptabilise la jambe trésorerie d'un paiement direct de commission
 * logistique (CommissionPayment) — circuit parallèle à PaiementFiche pour les
 * mêmes commissions, verrouillé contre le double paiement par
 * PeriodePayabilityChecker::assertPartsNotClaimedByFiche(). Avant l'audit du
 * 2026-08-22 (revue Codex), ce circuit n'avait AUCUNE écriture dans
 * compta_ecritures (seulement JournalTresorerie) : TresorerieDisponibiliteService
 * ignorait silencieusement ces décaissements, surestimant le disponible d'une
 * agence dont les livreurs sont payés par ce circuit plutôt que par fiche.
 *
 * Volontairement minimal (pas d'engagement/dette préalable, même limite que
 * PaieComptabilisationService) — et volontairement BLOQUANT (pas de mode
 * shadow) : ce paiement déplace de la trésorerie réelle, cf.
 * CommissionPaymentService::payer()/payerLivreur() qui englobent déjà la
 * création dans une DB::transaction — un échec ici annule tout le paiement.
 */
class CommissionPaymentComptabilisationService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    public function comptabiliserPaiement(CommissionPayment $paiement): ?PieceComptable
    {
        if (! in_array($paiement->beneficiary_type, ['livreur', 'proprietaire'], true)) {
            Log::error('Comptabilisation paiement commission logistique : type de bénéficiaire inconnu', [
                'paiement_id' => $paiement->id,
                'beneficiary_type' => $paiement->beneficiary_type,
            ]);

            return null;
        }

        $roleCharge = $paiement->beneficiary_type === 'livreur' ? 'charge_commission_livreur' : 'charge_commission_proprietaire';

        $lignes = [
            [
                'role' => $roleCharge,
                'sens' => 'debit',
                'montant' => (float) $paiement->montant,
            ],
            [
                'role' => 'tresorerie',
                'sens' => 'credit',
                'montant' => (float) $paiement->montant,
                'moyen_paiement' => $paiement->mode_paiement,
            ],
        ];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::PAIEMENT_COMMISSION_LOGISTIQUE_DIRECT,
            source: $paiement,
            organizationId: $paiement->organization_id,
            dateComptable: Carbon::parse($paiement->paid_at ?? now()),
            libelle: 'Paiement commission logistique — '.$paiement->beneficiary_nom,
            lignes: $lignes,
            siteId: $paiement->vehicule?->site_id,
            createdBy: $paiement->created_by,
        );
    }
}
