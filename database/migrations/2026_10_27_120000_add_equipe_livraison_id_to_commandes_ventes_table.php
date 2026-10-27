<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Équipe de livraison du véhicule figée au chargement (docs/commissions.md, COMM-023) : la
 * commission Livreurs va à l'équipe qui a chargé, même si l'équipe du véhicule change ou est
 * supprimée avant le calcul. Aucune reprise de l'historique : NULL = comportement antérieur
 * (équipe actuelle du véhicule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commandes_ventes', 'equipe_livraison_id')) {
            return;
        }

        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->foreignUlid('equipe_livraison_id')
                ->nullable()
                ->after('vehicule_id')
                ->constrained('equipes_livraison')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('commandes_ventes', 'equipe_livraison_id')) {
            return;
        }

        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipe_livraison_id');
        });
    }
};
