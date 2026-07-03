<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['commission_parts', 'commission_logistique_parts'] as $table) {
            $indexCol = $table === 'commission_parts' ? 'commission_vente_id' : 'commission_logistique_id';
            $indexName = $table === 'commission_parts' ? 'comm_parts_id_validated_idx' : 'comm_log_parts_id_validated_idx';

            Schema::table($table, function (Blueprint $blueprint) use ($indexCol, $indexName) {
                $blueprint->decimal('montant_actuel', 15, 2)->nullable()->after('montant_net');
                $blueprint->string('origine', 20)->default('theorique')->after('statut');
                $blueprint->foreignUlid('validated_by')->nullable()->after('origine')->constrained('users')->nullOnDelete();
                $blueprint->timestamp('validated_at')->nullable()->after('validated_by');
                $blueprint->index([$indexCol, 'validated_at'], $indexName);
            });

            // Rétrocompatibilité : les parts déjà générées avant cette fonctionnalité
            // sont considérées comme théoriques et déjà validées (sinon tout paiement
            // en cours via les flux existants serait bloqué par le nouveau gate).
            DB::table($table)->update([
                'origine' => 'theorique',
                'validated_at' => DB::raw('created_at'),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['commission_parts', 'commission_logistique_parts'] as $table) {
            $indexName = $table === 'commission_parts' ? 'comm_parts_id_validated_idx' : 'comm_log_parts_id_validated_idx';

            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
                $blueprint->dropForeign(['validated_by']);
                $blueprint->dropColumn(['montant_actuel', 'origine', 'validated_by', 'validated_at']);
            });
        }
    }
};
