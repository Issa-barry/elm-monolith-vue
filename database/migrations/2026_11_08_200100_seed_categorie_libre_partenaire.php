<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migration de DONNÉES (ADR 0060, lot 6a, décision G) — crée la catégorie libre « Partenaire »
 * dans chaque organisation existante. Rejouable sans doublon : l'index unique
 * (organization_id, code) écarte une organisation qui a déjà le code `partenaire`, même renommée
 * ou désactivée depuis. Les organisations créées ensuite la reçoivent par Organization::booted()
 * (CategoriesLibresParDefaut::initialiserPour()). Valeurs figées ici pour que la migration
 * produise toujours le même résultat.
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();

        DB::table('organizations')->orderBy('id')->pluck('id')->each(function (string $organizationId) use ($maintenant) {
            DB::table('contact_categories')->insertOrIgnore([
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'code' => 'partenaire',
                'libelle' => 'Partenaire',
                'is_active' => true,
                'ordre' => 1,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        });
    }

    /** Pas de retrait de données : la table elle-même est retirée par la migration de schéma. */
    public function down(): void
    {
        //
    }
};
