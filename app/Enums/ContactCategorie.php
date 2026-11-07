<?php

namespace App\Enums;

/**
 * Catégorie (fonction métier) d'un contact du référentiel Contacts (ADR 0060). Une catégorie
 * correspond à une table de fonction existante (`clients`, `fournisseurs`...) : elle ne se crée
 * ni ne se désactive depuis les Paramètres, seules ses sous-catégories sont administrables.
 */
enum ContactCategorie: string
{
    case CLIENT = 'client';
    case FOURNISSEUR = 'fournisseur';
    case PRESTATAIRE = 'prestataire';
    case PROPRIETAIRE = 'proprietaire';
    case PARRAIN = 'parrain';

    public function label(): string
    {
        return match ($this) {
            self::CLIENT => 'Client',
            self::FOURNISSEUR => 'Fournisseur',
            self::PRESTATAIRE => 'Prestataire',
            self::PROPRIETAIRE => 'Propriétaire',
            self::PARRAIN => 'Parrain',
        };
    }
}
