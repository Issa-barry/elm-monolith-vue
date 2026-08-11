<?php

namespace App\Enums;

/**
 * Cycle métier d'une commission (ou d'une fiche de paiement), indépendant de son
 * statut de paiement (`StatutCommission`/`StatutFichePaiement`) et du statut brut
 * de sa `PaiementPeriode` (`StatutPeriodePaiement`). Répond à une seule question :
 * "cette commission est-elle prête à être payée ?" — jamais "combien reste-t-il".
 *
 * - CREEE : pas encore rattachée à une PaiementPeriode calculée.
 * - EN_ATTENTE_VALIDATION : rattachée à une période, mais l'équipe/véhicule n'est
 *   pas (ou pas entièrement) validé(e), ou la période elle-même n'est pas VALIDEE.
 * - VALIDEE : la période est VALIDEE — la commission est payable, son statut de
 *   paiement réel (à payer / partiel / payé) prend le relais pour l'affichage.
 * - ANNULEE : la commande/le transfert d'origine a été annulé.
 */
enum StatutCommissionMetier: string
{
    case CREEE = 'creee';
    case EN_ATTENTE_VALIDATION = 'en_attente_validation';
    case VALIDEE = 'validee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::CREEE => 'Créée',
            self::EN_ATTENTE_VALIDATION => 'En attente de validation',
            self::VALIDEE => 'Validée',
            self::ANNULEE => 'Annulée',
        };
    }

    /** Clé compatible StatusDot::STATUS_COLOR_MAP. */
    public function dotStatus(): string
    {
        return match ($this) {
            self::CREEE => 'brouillon',
            self::EN_ATTENTE_VALIDATION => 'en_attente',
            self::VALIDEE => 'validee',
            self::ANNULEE => 'annulee',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
