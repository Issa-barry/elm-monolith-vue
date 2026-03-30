<?php

namespace App\Enums;

enum PrestataireType: string
{
    case MACHINISTE = 'machiniste';
    case MECANICIEN = 'mecanicien';
    case CONSULTANT = 'consultant';
    case FOURNISSEUR = 'fournisseur';

    public function label(): string
    {
        return match ($this) {
            self::MACHINISTE => 'Machiniste',
            self::MECANICIEN => 'Mécanicien',
            self::CONSULTANT => 'Consultant',
            self::FOURNISSEUR => 'Fournisseur',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn ($case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
