<?php

namespace App\Support\Contacts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catégories libres créées pour chaque organisation (ADR 0060, lot 6a, décision G) : « Partenaire »,
 * renommable et désactivable. Rejouable sans doublon : l'index unique (organization_id, code)
 * écarte une organisation qui a déjà le code, même renommé ou désactivé.
 */
final class CategoriesLibresParDefaut
{
    public const PARTENAIRE = 'partenaire';

    /** @return int nombre de lignes créées */
    public static function initialiserPour(string $organizationId): int
    {
        $maintenant = now();

        return DB::table('contact_categories')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $organizationId,
            'code' => self::PARTENAIRE,
            'libelle' => 'Partenaire',
            'is_active' => true,
            'ordre' => 1,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }
}
