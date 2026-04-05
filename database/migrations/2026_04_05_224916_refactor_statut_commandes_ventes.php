<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor du cycle de vie des commandes de vente.
 *
 * Anciens statuts : en_cours | livree | cloturee | annulee
 * Nouveaux statuts : brouillon | validee | cloturee | annulee
 *
 * Mapping backfill :
 *   en_cours  → validee  (déjà une facture associée)
 *   livree    → cloturee
 *   cloturee  → cloturee (inchangé)
 *   annulee   → annulee  (inchangé)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->timestamp('validated_at')->nullable()->after('annulee_at');
            $table->timestamp('closed_at')->nullable()->after('validated_at');
        });

        // en_cours → validee (validated_at ≈ created_at)
        DB::table('commandes_ventes')
            ->where('statut', 'en_cours')
            ->update([
                'statut'       => 'validee',
                'validated_at' => DB::raw('created_at'),
            ]);

        // livree → cloturee (closed_at ≈ updated_at)
        DB::table('commandes_ventes')
            ->where('statut', 'livree')
            ->update([
                'statut'    => 'cloturee',
                'closed_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        // brouillon/validee → en_cours
        DB::table('commandes_ventes')
            ->whereIn('statut', ['brouillon', 'validee'])
            ->update(['statut' => 'en_cours', 'validated_at' => null, 'closed_at' => null]);

        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropColumn(['validated_at', 'closed_at']);
        });
    }
};
