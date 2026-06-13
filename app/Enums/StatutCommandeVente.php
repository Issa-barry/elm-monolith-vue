<?php

namespace App\Enums;

enum StatutCommandeVente: string
{
    case BROUILLON = 'brouillon';
    case A_CHARGER = 'a_charger';
    case CHARGEMENT_EN_COURS = 'chargement_en_cours';
    case LIVRAISON_EN_COURS = 'livraison_en_cours';
    case LIVREE = 'livree';
    case CLOTUREE = 'cloturee';
    case ANNULEE = 'annulee';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::A_CHARGER => 'À charger',
            self::CHARGEMENT_EN_COURS => 'Chargement en cours',
            self::LIVRAISON_EN_COURS => 'Livraison en cours',
            self::LIVREE => 'Livrée',
            self::CLOTUREE => 'Clôturée',
            self::ANNULEE => 'Annulée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON => 'secondary',
            self::A_CHARGER => 'warn',
            self::CHARGEMENT_EN_COURS => 'warn',
            self::LIVRAISON_EN_COURS => 'primary',
            self::LIVREE => 'success',
            self::CLOTUREE => 'success',
            self::ANNULEE => 'danger',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::BROUILLON => 'bg-zinc-400 dark:bg-zinc-500',
            self::A_CHARGER => 'bg-amber-400',
            self::CHARGEMENT_EN_COURS => 'bg-orange-500',
            self::LIVRAISON_EN_COURS => 'bg-blue-500',
            self::LIVREE => 'bg-teal-500',
            self::CLOTUREE => 'bg-emerald-500',
            self::ANNULEE => 'bg-red-400',
        };
    }

    /** Modifiable uniquement en brouillon */
    public function isEditable(): bool
    {
        return $this === self::BROUILLON;
    }

    /** Statuts terminaux — aucune transition possible */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOTUREE, self::ANNULEE]);
    }

    /** Annulable uniquement depuis BROUILLON ou A_CHARGER */
    public function isAnnulable(): bool
    {
        return in_array($this, [self::BROUILLON, self::A_CHARGER]);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
