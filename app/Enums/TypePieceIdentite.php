<?php

namespace App\Enums;

enum TypePieceIdentite: string
{
    case CNI = 'cni';
    case PASSEPORT = 'passeport';
    case PERMIS_CONDUIRE = 'permis_conduire';
    case CARTE_SEJOUR = 'carte_sejour';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::CNI => "Carte nationale d'identité",
            self::PASSEPORT => 'Passeport',
            self::PERMIS_CONDUIRE => 'Permis de conduire',
            self::CARTE_SEJOUR => 'Carte de séjour',
            self::AUTRE => 'Autre',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn ($c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
