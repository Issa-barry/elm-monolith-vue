<?php

namespace App\Enums;

enum SiteType: string
{
    case SIEGE = 'siege';
    case USINE = 'usine';
    case DEPOT = 'depot';
    case AGENCE = 'agence';

    public function label(): string
    {
        return match ($this) {
            self::SIEGE => 'Siège',
            self::USINE => 'Usine',
            self::DEPOT => 'Dépôt',
            self::AGENCE => 'Agence',
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
