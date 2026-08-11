<?php

namespace App\Services;

use App\Enums\StatutCommissionMetier;
use App\Enums\StatutValidationEquipe;
use App\Models\PaiementPeriode;

/**
 * Point unique de composition de l'affichage d'une ligne de commission (ou de fiche de
 * paiement). Remplace l'ancienne logique `statut_effectif` qui fusionnait silencieusement
 * trois axes indépendants en un seul badge. Ici, chaque axe reste distinct dans la sortie —
 * `display_status`/`display_label` ne sont qu'une projection pour le badge principal.
 *
 * Les trois axes sources de vérité restent ailleurs, inchangés :
 * - `PaiementPeriode::statut` (`StatutPeriodePaiement`) — le verrou de paiement réel,
 *   vérifié côté backend par `PeriodePayabilityChecker`.
 * - `StatutValidationEquipe` — calculé par `CommissionAdjustmentService` à partir de
 *   `validated_at` sur les parts d'un véhicule.
 * - Le statut de paiement brut du modèle (`StatutCommission`/`StatutFichePaiement`).
 */
final class CommissionStatusResolver
{
    /**
     * @param  ?PaiementPeriode  $periode  période résolue pour cette commission (null = pas encore calculée/rattachée)
     * @param  ?StatutValidationEquipe  $teamStatus  statut de validation de l'équipe/véhicule (null = non applicable, ex: fiche déjà agrégée)
     * @param  string  $paymentStatusValue  valeur brute du statut de paiement (StatutCommission::value ou StatutFichePaiement::value)
     * @param  string  $paymentStatusLabel  libellé humain du statut de paiement brut
     * @return array{
     *     periode_status: ?string, periode_status_label: ?string,
     *     team_validation_status: ?string, team_validation_status_label: ?string,
     *     commission_status: string, commission_status_label: string,
     *     payment_status: string, payment_status_label: string,
     *     display_status: string, display_label: string,
     *     can_pay: bool,
     * }
     */
    public static function resolve(
        ?PaiementPeriode $periode,
        ?StatutValidationEquipe $teamStatus,
        string $paymentStatusValue,
        string $paymentStatusLabel,
    ): array {
        $base = [
            'periode_status' => $periode?->statut?->value,
            'periode_status_label' => $periode?->statut_label,
            'team_validation_status' => $teamStatus?->value,
            'team_validation_status_label' => $teamStatus?->label(),
            'payment_status' => $paymentStatusValue,
            'payment_status_label' => $paymentStatusLabel,
        ];

        // Une commission annulée reste annulée quel que soit l'état de sa période.
        if ($paymentStatusValue === 'annulee') {
            return [
                ...$base,
                'commission_status' => StatutCommissionMetier::ANNULEE->value,
                'commission_status_label' => StatutCommissionMetier::ANNULEE->label(),
                'display_status' => 'annulee',
                'display_label' => 'Annulée',
                'can_pay' => false,
            ];
        }

        // Un montant déjà intégralement soldé reste "payé" quel que soit l'état de sa
        // période — en pratique inatteignable autrement que "validée" (le verrou
        // backend interdit tout paiement avant validation), mais on ne dépend pas de
        // cette garantie ici : le vrai statut de paiement ne doit jamais être masqué.
        if ($paymentStatusValue === 'paye') {
            return [
                ...$base,
                'commission_status' => StatutCommissionMetier::VALIDEE->value,
                'commission_status_label' => StatutCommissionMetier::VALIDEE->label(),
                'display_status' => 'paye',
                'display_label' => $paymentStatusLabel,
                'can_pay' => false,
            ];
        }

        // Pas encore rattachée à une période calculée : rien à afficher côté paiement.
        if ($periode === null) {
            return [
                ...$base,
                'commission_status' => StatutCommissionMetier::CREEE->value,
                'commission_status_label' => StatutCommissionMetier::CREEE->label(),
                'display_status' => 'creee',
                'display_label' => 'Créée',
                'can_pay' => false,
            ];
        }

        if ($periode->isValidee()) {
            $canPay = ! in_array($paymentStatusValue, ['paye', 'annulee'], true);

            return [
                ...$base,
                'commission_status' => StatutCommissionMetier::VALIDEE->value,
                'commission_status_label' => StatutCommissionMetier::VALIDEE->label(),
                'display_status' => $paymentStatusValue,
                'display_label' => $paymentStatusLabel,
                'can_pay' => $canPay,
            ];
        }

        if ($periode->isCloturee()) {
            // Le cas "paye" est déjà couvert plus haut (court-circuit global). Ce qui
            // reste ici est l'anomalie que le garde-fou de clôture est censé empêcher :
            // une période clôturée avec un solde encore dû. On l'affiche tel quel
            // plutôt que de le masquer (voir PaiementPeriodeController::cloturer).
            return [
                ...$base,
                'commission_status' => StatutCommissionMetier::VALIDEE->value,
                'commission_status_label' => StatutCommissionMetier::VALIDEE->label(),
                'display_status' => 'cloturee',
                'display_label' => 'Clôturée',
                'can_pay' => false,
            ];
        }

        // Période brouillon ou calculée : jamais payable. On distingue seulement si la
        // répartition de l'équipe est déjà prête, pour donner un signal de progression.
        if ($teamStatus !== null && $teamStatus->estValidee()) {
            return [
                ...$base,
                'commission_status' => StatutCommissionMetier::EN_ATTENTE_VALIDATION->value,
                'commission_status_label' => StatutCommissionMetier::EN_ATTENTE_VALIDATION->label(),
                'display_status' => 'repartition_validee',
                'display_label' => 'Répartition validée — période en attente',
                'can_pay' => false,
            ];
        }

        return [
            ...$base,
            'commission_status' => StatutCommissionMetier::EN_ATTENTE_VALIDATION->value,
            'commission_status_label' => StatutCommissionMetier::EN_ATTENTE_VALIDATION->label(),
            'display_status' => 'en_attente',
            'display_label' => 'En attente de validation',
            'can_pay' => false,
        ];
    }
}
