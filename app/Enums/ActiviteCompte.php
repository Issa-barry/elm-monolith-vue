<?php

namespace App\Enums;

use App\Models\CommissionEnveloppePart;

/**
 * Activité au titre de laquelle un compte de l'espace client perçoit une commission (ADR 0054).
 * Elle vient toujours du type de bénéficiaire de la part enregistrée, jamais des rôles du compte :
 * une même personne peut être propriétaire, livreur, parrain et consultant.
 */
enum ActiviteCompte: string
{
    case PROPRIETAIRE = 'proprietaire';
    case LIVREUR = 'livreur';
    case PARRAIN = 'parrain';
    case CONSULTANT = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::PROPRIETAIRE => 'Propriétaire',
            self::LIVREUR => 'Livreur',
            self::PARRAIN => 'Parrain',
            self::CONSULTANT => 'Consultant',
        };
    }

    public function beneficiaireType(): string
    {
        return match ($this) {
            self::PROPRIETAIRE => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            self::LIVREUR => CommissionEnveloppePart::TYPE_LIVREUR,
            self::PARRAIN => CommissionEnveloppePart::TYPE_PARRAIN,
            self::CONSULTANT => CommissionEnveloppePart::TYPE_PRESTATAIRE,
        };
    }

    public static function depuisBeneficiaireType(?string $type): ?self
    {
        foreach (self::cases() as $activite) {
            if ($activite->beneficiaireType() === $type) {
                return $activite;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $activite) => $activite->value, self::cases());
    }
}
