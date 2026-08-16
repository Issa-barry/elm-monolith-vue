<?php

namespace App\Enums;

enum SiteType: string
{
    case SIEGE = 'siege';
    case USINE = 'usine';
    case DEPOT = 'depot';
    case AGENCE = 'agence';
    case BOUTIQUE = 'boutique';
    case RESTAURANT = 'restaurant';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::SIEGE => 'Siège',
            // Couvre aussi "Atelier" à l'échelle artisanale — un seul type technique, pas de
            // doublon pour la même signification métier (cf. DomaineActivite::siteTypes()).
            // Libellé volontairement inchangé ("Usine", pas "Usine / Atelier") : SiteImportParser
            // fait correspondre le type saisi dans un import CSV au libellé EXACT — le modifier
            // casserait les imports déjà en circulation chez des organisations existantes.
            self::USINE => 'Usine',
            // Couvre aussi "Entrepôt" — même type technique que Dépôt, même raison ci-dessus
            // pour ne pas renommer le libellé existant.
            self::DEPOT => 'Dépôt',
            self::AGENCE => 'Agence',
            self::BOUTIQUE => 'Boutique / Point de vente',
            self::RESTAURANT => 'Restaurant',
            self::AUTRE => 'Autre',
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
