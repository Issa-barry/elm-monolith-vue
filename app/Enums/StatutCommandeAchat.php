<?php

namespace App\Enums;

/**
 * Cycle d'un bon de commande fournisseur (ADR 0021) :
 * à valider → validée → partiellement réceptionnée → réceptionnée (ou clôturée si le reliquat est
 * abandonné) ; annulée tant qu'aucune réception n'a eu lieu.
 *
 * `en_cours` est la valeur des commandes créées avant la refonte (jamais validées) : elle est
 * conservée telle quelle en base et traitée comme « à valider ».
 */
enum StatutCommandeAchat: string
{
    case A_VALIDER = 'a_valider';
    case EN_COURS = 'en_cours';
    case VALIDEE = 'validee';
    case PARTIELLEMENT_RECEPTIONNEE = 'partiellement_receptionnee';
    case RECEPTIONNEE = 'receptionnee';
    case CLOTUREE = 'cloturee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::A_VALIDER => 'À valider',
            self::EN_COURS => 'En cours',
            self::VALIDEE => 'Validée',
            self::PARTIELLEMENT_RECEPTIONNEE => 'Partiellement réceptionnée',
            self::RECEPTIONNEE => 'Réceptionnée',
            self::CLOTUREE => 'Clôturée',
            self::ANNULEE => 'Annulée',
        };
    }

    /** Modifiable et en attente de validation. */
    public function estAValider(): bool
    {
        return in_array($this, [self::A_VALIDER, self::EN_COURS], true);
    }

    /** Peut recevoir une (nouvelle) réception. */
    public function estReceptionnable(): bool
    {
        return in_array($this, [self::VALIDEE, self::PARTIELLEMENT_RECEPTIONNEE], true);
    }

    /** @return list<self> */
    public static function aValider(): array
    {
        return [self::A_VALIDER, self::EN_COURS];
    }

    /** @return list<self> */
    public static function receptionnables(): array
    {
        return [self::VALIDEE, self::PARTIELLEMENT_RECEPTIONNEE];
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            array_values(array_filter(self::cases(), fn (self $case) => $case !== self::EN_COURS))
        );
    }
}
