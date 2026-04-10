<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unicité équipe <-> véhicule : une équipe ne peut être affectée qu'à un seul véhicule.
 *
 * Stratégie avant la contrainte :
 *  1. Les véhicules soft-deleted libèrent leur équipe (equipe_livraison_id → NULL).
 *  2. Les doublons actifs sont résolus en gardant le véhicule le plus récent
 *     et en désolidarisant les plus anciens (equipe_livraison_id → NULL).
 *
 * MySQL autorise plusieurs NULL dans un index unique, donc les véhicules
 * sans équipe (suite à suppression d'équipe via nullOnDelete) ne conflictent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Libérer les équipes des véhicules soft-deleted
        DB::table('vehicules')
            ->whereNotNull('equipe_livraison_id')
            ->whereNotNull('deleted_at')
            ->update(['equipe_livraison_id' => null]);

        // 2. Résoudre les doublons actifs (garder le plus récent)
        $duplicates = DB::table('vehicules')
            ->whereNotNull('equipe_livraison_id')
            ->whereNull('deleted_at')
            ->select('equipe_livraison_id', DB::raw('MAX(id) as keep_id'))
            ->groupBy('equipe_livraison_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('vehicules')
                ->where('equipe_livraison_id', $row->equipe_livraison_id)
                ->where('id', '<>', $row->keep_id)
                ->whereNull('deleted_at')
                ->update(['equipe_livraison_id' => null]);
        }

        // 3. Ajouter la contrainte unique
        Schema::table('vehicules', function (Blueprint $table) {
            $table->unique('equipe_livraison_id', 'vehicules_equipe_livraison_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropUnique('vehicules_equipe_livraison_id_unique');
        });
    }
};
