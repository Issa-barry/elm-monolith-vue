<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stratégie de nettoyage des doublons actifs :
 *
 * Pour chaque groupe (organization_id, telephone|email) avec plusieurs enregistrements actifs :
 *   1. Conserver le propriétaire le plus ancien (id MIN) comme canonique.
 *   2. Réaffecter les relations (vehicules, equipes_livraison, commission_parts) vers le canonique.
 *   3. Soft-delete les doublons.
 *
 * Index ajoutés :
 *   - (organization_id, telephone) — index composite pour les performances.
 *   - (organization_id, email)     — idem.
 *
 * Note : une contrainte UNIQUE DB n'est pas appliquée car les enregistrements
 * soft-deletés conservent leur téléphone/email en base, ce qui provoquerait des
 * violations d'index en MySQL (NULL != NULL non garanti sur toutes versions).
 * L'unicité est garantie côté application (whereNull('deleted_at') + organisation).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeActiveDuplicates('telephone');
        $this->mergeActiveDuplicates('email');

        Schema::table('proprietaires', function (Blueprint $table) {
            $table->index(['organization_id', 'telephone'], 'proprietaires_org_tel_idx');
            $table->index(['organization_id', 'email'], 'proprietaires_org_email_idx');
        });
    }

    public function down(): void
    {
        Schema::table('proprietaires', function (Blueprint $table) {
            $table->dropIndex('proprietaires_org_tel_idx');
            $table->dropIndex('proprietaires_org_email_idx');
        });
    }

    private function mergeActiveDuplicates(string $field): void
    {
        $groups = DB::table('proprietaires')
            ->whereNull('deleted_at')
            ->whereNotNull($field)
            ->select(
                'organization_id',
                $field,
                DB::raw('MIN(id) as canonical_id'),
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy('organization_id', $field)
            ->having('cnt', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $canonicalId = $group->canonical_id;

            $duplicateIds = DB::table('proprietaires')
                ->where('organization_id', $group->organization_id)
                ->where($field, $group->$field)
                ->whereNull('deleted_at')
                ->where('id', '!=', $canonicalId)
                ->pluck('id');

            foreach ($duplicateIds as $dupId) {
                // Réaffecter les véhicules (restrictOnDelete — doit être fait avant soft-delete)
                DB::table('vehicules')
                    ->where('proprietaire_id', $dupId)
                    ->update(['proprietaire_id' => $canonicalId]);

                // Réaffecter les équipes de livraison (restrictOnDelete)
                DB::table('equipes_livraison')
                    ->where('proprietaire_id', $dupId)
                    ->update(['proprietaire_id' => $canonicalId]);

                // Réaffecter les parts de commission (nullOnDelete, mais on préfère conserver)
                DB::table('commission_parts')
                    ->where('proprietaire_id', $dupId)
                    ->update(['proprietaire_id' => $canonicalId]);

                // Soft-delete le doublon
                DB::table('proprietaires')
                    ->where('id', $dupId)
                    ->update(['deleted_at' => now()]);
            }
        }
    }
};
