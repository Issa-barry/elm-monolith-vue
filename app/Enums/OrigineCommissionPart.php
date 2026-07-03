<?php

namespace App\Enums;

enum OrigineCommissionPart: string
{
    /** Générée automatiquement depuis la configuration théorique de l'équipe */
    case THEORIQUE = 'theorique';

    /** Ajoutée manuellement par un responsable (ex: prime, correction ponctuelle) */
    case AJOUT_MANUEL = 'ajout_manuel';

    /** Ajoutée pour un bénéficiaire remplaçant, absent de l'équipe théorique */
    case REMPLACEMENT = 'remplacement';

    public function label(): string
    {
        return match ($this) {
            self::THEORIQUE => 'Théorique',
            self::AJOUT_MANUEL => 'Ajout manuel',
            self::REMPLACEMENT => 'Remplacement',
        };
    }

    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
