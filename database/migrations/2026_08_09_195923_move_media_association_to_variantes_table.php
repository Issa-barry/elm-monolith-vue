<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige le sens de l'association média ↔ variante posée en Phase 1 : un FK
 * `produit_medias.produit_variante_id` ne permettrait qu'à UNE SEULE variante de référencer
 * chaque photo (une colonne sur le média, pas sur la variante), ce qui obligerait à dupliquer
 * physiquement l'image pour chaque pointure d'une même couleur. Cette colonne n'a d'ailleurs
 * jamais été peuplée (aucun code ne l'utilisait). Le bon sens est l'inverse :
 * `produit_variantes.media_id` — plusieurs variantes (ex: toutes les pointures "Blanc") peuvent
 * alors partager la même ligne `produit_medias`, sans duplication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_medias', function (Blueprint $table) {
            $table->dropForeign(['produit_variante_id']);
            $table->dropColumn('produit_variante_id');
        });

        Schema::table('produit_variantes', function (Blueprint $table) {
            // nullOnDelete : la suppression d'un média ne doit jamais laisser de référence
            // cassée — la variante retombe simplement sur l'image principale du produit
            // (cf. ProduitVariante::getEffectiveImageUrlAttribute()).
            $table->foreignUlid('media_id')->nullable()->after('combo_hash')
                ->constrained('produit_medias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('produit_variantes', function (Blueprint $table) {
            $table->dropForeign(['media_id']);
            $table->dropColumn('media_id');
        });

        Schema::table('produit_medias', function (Blueprint $table) {
            $table->foreignUlid('produit_variante_id')->nullable()->constrained('produit_variantes')->nullOnDelete();
        });
    }
};
