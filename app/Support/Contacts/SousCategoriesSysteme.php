<?php

namespace App\Support\Contacts;

use App\Enums\ClientType;
use App\Enums\ContactCategorie;
use App\Enums\PrestataireType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sous-catégories créées pour chaque organisation (ADR 0060). Leurs codes sont les valeurs déjà
 * stockées dans `clients.type` et `prestataires.type`, pour que le référentiel ne change rien aux
 * données existantes.
 *
 * Système (D18) : les quatre natures client, qui fixent les prix, et Consultant, qui conditionne
 * les commissions. Leur code et leur activation sont verrouillés (ContactSousCategorie). Machiniste
 * et mécanicien sont créés modifiables : aucune règle métier ne les lit.
 */
final class SousCategoriesSysteme
{
    /**
     * @return list<array{categorie: string, code: string, libelle: string, is_system: bool, ordre: int}>
     */
    public static function lignesParDefaut(): array
    {
        $lignes = [];

        foreach (ClientType::cases() as $i => $nature) {
            $lignes[] = [
                'categorie' => ContactCategorie::CLIENT->value,
                'code' => $nature->value,
                'libelle' => $nature->label(),
                'is_system' => true,
                'ordre' => $i + 1,
            ];
        }

        foreach (PrestataireType::cases() as $i => $type) {
            $lignes[] = [
                'categorie' => ContactCategorie::PRESTATAIRE->value,
                'code' => $type->value,
                'libelle' => $type->label(),
                'is_system' => $type === PrestataireType::CONSULTANT,
                'ordre' => $i + 1,
            ];
        }

        return $lignes;
    }

    public static function estSysteme(string $categorie, string $code): bool
    {
        foreach (self::lignesParDefaut() as $ligne) {
            if ($ligne['categorie'] === $categorie && $ligne['code'] === $code) {
                return $ligne['is_system'];
            }
        }

        return false;
    }

    /**
     * Crée les sous-catégories manquantes de l'organisation, sans toucher à celles qui existent
     * (libellé renommé, ordre changé) : rejouable sans doublon, l'index unique
     * (organization_id, categorie, code) écarte les lignes déjà présentes.
     *
     * @return int nombre de lignes créées
     */
    public static function initialiserPour(string $organizationId): int
    {
        $maintenant = now();

        $lignes = array_map(fn (array $ligne) => [
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $organizationId,
            ...$ligne,
            'is_active' => true,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], self::lignesParDefaut());

        return DB::table('contact_sous_categories')->insertOrIgnore($lignes);
    }
}
