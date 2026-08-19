<?php

namespace App\Enums;

/**
 * Nature juridique d'un propriétaire de véhicule (Proprietaire) — indépendante de
 * Vehicule::categorie (interne/partenaire) : un propriétaire interne par défaut peut tout à
 * fait être une entreprise (ex: la société elle-même), tout comme un propriétaire tiers peut
 * être une simple personne physique. Les deux distinctions ne se recoupent jamais.
 */
enum TypeProprietaire: string
{
    case PERSONNE_PHYSIQUE = 'personne_physique';
    case ENTREPRISE = 'entreprise';

    public function label(): string
    {
        return match ($this) {
            self::PERSONNE_PHYSIQUE => 'Personne physique',
            self::ENTREPRISE => 'Entreprise',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
