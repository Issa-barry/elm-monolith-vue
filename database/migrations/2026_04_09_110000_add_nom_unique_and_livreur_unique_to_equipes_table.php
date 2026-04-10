<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Supprimer les membres des équipes soft-deletées (libérer livreur_id pour la contrainte unique à venir)
        $deletedIds = DB::table('equipes_livraison')->whereNotNull('deleted_at')->pluck('id');
        if ($deletedIds->isNotEmpty()) {
            DB::table('equipe_livreurs')->whereIn('equipe_id', $deletedIds)->delete();
        }

        // 2. Résoudre les doublons : un livreur dans plusieurs équipes actives → garder l'entrée la plus récente
        $livreurIds = DB::table('equipe_livreurs as el')
            ->join('equipes_livraison as e', 'el.equipe_id', '=', 'e.id')
            ->whereNull('e.deleted_at')
            ->select('el.livreur_id')
            ->groupBy('el.livreur_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('el.livreur_id');

        foreach ($livreurIds as $livreurId) {
            $keepId = DB::table('equipe_livreurs as el')
                ->join('equipes_livraison as e', 'el.equipe_id', '=', 'e.id')
                ->whereNull('e.deleted_at')
                ->where('el.livreur_id', $livreurId)
                ->orderByDesc('el.id')
                ->value('el.id');

            DB::table('equipe_livreurs')
                ->where('livreur_id', $livreurId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        // 3. Résoudre les doublons de nom par organisation (suffixer avec "(doublon N)")
        $dupNoms = DB::table('equipes_livraison')
            ->whereNull('deleted_at')
            ->select('organization_id', 'nom')
            ->groupBy('organization_id', 'nom')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupNoms as $dup) {
            $rows = DB::table('equipes_livraison')
                ->where('organization_id', $dup->organization_id)
                ->where('nom', $dup->nom)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->slice(1);

            foreach ($rows->values() as $i => $row) {
                DB::table('equipes_livraison')
                    ->where('id', $row->id)
                    ->update(['nom' => $dup->nom.' (doublon '.($i + 1).')']);
            }
        }

        // 4. Contrainte UNIQUE(livreur_id) sur equipe_livreurs
        // (La nom+org uniqueness est gérée au niveau applicatif car MySQL ne supporte pas les index partiels)
        Schema::table('equipe_livreurs', function (Blueprint $table) {
            $table->unique('livreur_id', 'equipe_livreurs_livreur_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('equipe_livreurs', function (Blueprint $table) {
            $table->dropUnique('equipe_livreurs_livreur_id_unique');
        });
    }
};
