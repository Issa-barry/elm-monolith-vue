<?php

namespace App\Enums;

enum SensJournal: string
{
    case ENTREE = 'entree';
    case SORTIE = 'sortie';

    public function label(): string
    {
        return match ($this) {
            self::ENTREE => 'Entrée',
            self::SORTIE => 'Sortie',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
