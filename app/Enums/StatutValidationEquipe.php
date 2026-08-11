<?php

namespace App\Enums;

/**
 * Statut agrégé de validation d'une équipe/véhicule sur une période : est-ce que
 * toutes les parts de commission (vente + logistique confondues) rattachées à ce
 * véhicule ont été individuellement validées par un responsable (`validated_at`) ?
 * Calculé par `CommissionAdjustmentService::statutValidationPourParts()`.
 */
enum StatutValidationEquipe: string
{
    case A_VERIFIER = 'a_verifier';
    case VALIDEE = 'validee';
    case A_REVERIFIER = 'a_reverifier';
    case PAYEE = 'payee';

    public function label(): string
    {
        return match ($this) {
            self::A_VERIFIER => 'À vérifier',
            self::VALIDEE => 'Validée',
            self::A_REVERIFIER => 'À revérifier',
            self::PAYEE => 'Payée',
        };
    }

    public function estValidee(): bool
    {
        return in_array($this, [self::VALIDEE, self::PAYEE], true);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
