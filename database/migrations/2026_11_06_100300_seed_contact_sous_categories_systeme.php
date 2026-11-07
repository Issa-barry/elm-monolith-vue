<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migration de DONNÉES (ADR 0060) — crée les sous-catégories par défaut de chaque organisation
 * existante : les quatre natures client et Consultant (système, verrouillées — D18), machiniste et
 * mécanicien (modifiables). Les codes reprennent les valeurs déjà stockées dans `clients.type` et
 * `prestataires.type` : aucune ligne existante n'est modifiée.
 *
 * Liste figée ici (et non lue depuis le code applicatif) pour que la migration produise toujours
 * le même résultat. Rejouable sans doublon : l'index unique (organization_id, categorie, code)
 * écarte les lignes déjà présentes. Les organisations créées ensuite sont initialisées par
 * Organization::booted() (SousCategoriesSysteme::initialiserPour()).
 */
return new class extends Migration
{
    private const LIGNES = [
        ['client', 'externe', 'Externe', true, 1],
        ['client', 'revendeur', 'Revendeur', true, 2],
        ['client', 'distributeur', 'Distributeur', true, 3],
        ['client', 'grossiste', 'Grossiste', true, 4],
        ['prestataire', 'machiniste', 'Machiniste', false, 1],
        ['prestataire', 'mecanicien', 'Mécanicien', false, 2],
        ['prestataire', 'consultant', 'Consultant', true, 3],
    ];

    public function up(): void
    {
        $maintenant = now();

        DB::table('organizations')->orderBy('id')->pluck('id')->each(function (string $organizationId) use ($maintenant) {
            DB::table('contact_sous_categories')->insertOrIgnore(array_map(fn (array $l) => [
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'categorie' => $l[0],
                'code' => $l[1],
                'libelle' => $l[2],
                'is_system' => $l[3],
                'is_active' => true,
                'ordre' => $l[4],
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ], self::LIGNES));
        });
    }

    /** Pas de retrait de données : la table elle-même est retirée par la migration de schéma. */
    public function down(): void
    {
        //
    }
};
