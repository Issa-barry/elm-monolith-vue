<?php

namespace App\Enums;

enum SiteRole: string
{
    case RESPONSABLE = 'responsable';
    case EMPLOYE     = 'employe';

    public function label(): string
    {
        return match($this) {
            self::RESPONSABLE => 'Responsable',
            self::EMPLOYE     => 'Employé',
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
