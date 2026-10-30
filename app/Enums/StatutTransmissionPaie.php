<?php

namespace App\Enums;

/**
 * Cycle d'une version de transmission mensuelle RH → Comptabilité (docs/transmission-paie.md,
 * ADR 0040) : brouillon (montants calculés, recalculables) → validée (figée) → transmise →
 * remplacée par une version suivante. Un brouillon peut être abandonné.
 */
enum StatutTransmissionPaie: string
{
    case BROUILLON = 'brouillon';
    case VALIDEE = 'validee';
    case TRANSMISE = 'transmise';
    case REMPLACEE = 'remplacee';
    case ABANDONNEE = 'abandonnee';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDEE => 'Validée (figée)',
            self::TRANSMISE => 'Transmise',
            self::REMPLACEE => 'Remplacée',
            self::ABANDONNEE => 'Abandonnée',
        };
    }

    /** Les lignes ne changent plus jamais. */
    public function estFigee(): bool
    {
        return in_array($this, [self::VALIDEE, self::TRANSMISE, self::REMPLACEE], true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
