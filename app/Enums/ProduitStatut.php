<?php

namespace App\Enums;

enum ProduitStatut: string
{
    case ACTIF   = 'actif';
    case INACTIF = 'inactif';
    case ARCHIVE = 'archive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIF   => 'Actif',
            self::INACTIF => 'Inactif',
            self::ARCHIVE => 'Archivé',
        };
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::ACTIF   => [self::INACTIF, self::ARCHIVE],
            self::INACTIF => [self::ACTIF, self::ARCHIVE],
            self::ARCHIVE => [self::ACTIF, self::INACTIF],
        };
    }

    public function canTransitionTo(self $new): bool
    {
        return in_array($new, $this->allowedTransitions());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
