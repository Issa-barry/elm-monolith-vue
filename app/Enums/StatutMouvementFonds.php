<?php

namespace App\Enums;

/**
 * Workflow : BROUILLON → ENVOYE → RECU (nominal), ou BROUILLON → ANNULE et
 * ENVOYE → REJETE (contestation à réception, cf. MouvementFondsService).
 * Aucune transition n'est possible depuis RECU/ANNULE/REJETE (terminaux).
 */
enum StatutMouvementFonds: string
{
    case BROUILLON = 'brouillon';
    case ENVOYE = 'envoye';
    case RECU = 'recu';
    case ANNULE = 'annule';
    case REJETE = 'rejete';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::ENVOYE => 'Envoyé',
            self::RECU => 'Reçu',
            self::ANNULE => 'Annulé',
            self::REJETE => 'Rejeté',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::RECU, self::ANNULE, self::REJETE], true);
    }

    public function isEnTransit(): bool
    {
        return $this === self::ENVOYE;
    }
}
