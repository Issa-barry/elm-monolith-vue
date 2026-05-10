<?php

namespace App\Enums;

enum StatutPropositionVehicule: string
{
    case PENDING = 'pending';
    case SOUMISE = 'soumise';
    case EN_REVISION = 'en_revision';
    case A_COMPLETER = 'a_completer';
    case REJETEE = 'rejetee';
    case CONVERTIE = 'convertie';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::SOUMISE => 'Soumise',
            self::EN_REVISION => 'En révision',
            self::A_COMPLETER => 'À compléter',
            self::REJETEE => 'Rejetée',
            self::CONVERTIE => 'Convertie',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'amber',
            self::SOUMISE => 'amber',
            self::EN_REVISION => 'blue',
            self::A_COMPLETER => 'orange',
            self::REJETEE => 'red',
            self::CONVERTIE => 'emerald',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::REJETEE, self::CONVERTIE], true);
    }

    /** @return array<array{value:string,label:string,color:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases()
        );
    }
}
