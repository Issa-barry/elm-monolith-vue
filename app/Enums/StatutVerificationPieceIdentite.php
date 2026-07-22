<?php

namespace App\Enums;

enum StatutVerificationPieceIdentite: string
{
    case EN_ATTENTE = 'en_attente';
    case VALIDEE = 'validee';
    case REJETEE = 'rejetee';

    public function label(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::VALIDEE => 'Validée',
            self::REJETEE => 'Rejetée',
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
