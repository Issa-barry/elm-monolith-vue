<?php

namespace App\Enums;

enum SiteStatut: string
{
    case ACTIVE    = 'active';
    case INACTIVE  = 'inactive';
    case SUSPENDUE = 'suspendue';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE    => 'Actif',
            self::INACTIVE  => 'Inactif',
            self::SUSPENDUE => 'Suspendu',
        };
    }

    public static function options(): array
    {
        return array_map(fn ($c) => [
            'value' => $c->value,
            'label' => $c->label(),
        ], self::cases());
    }
}
