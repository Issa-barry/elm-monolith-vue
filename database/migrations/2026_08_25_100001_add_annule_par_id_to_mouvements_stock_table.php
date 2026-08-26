<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace le contre-mouvement qui annule un mouvement donné — remplace la suppression réelle
 * (MouvementStockService::annulerMouvement() faisait un delete()) par une contre-passation
 * traçable (audit stock du 25/08/2026, même principe d'immuabilité que StockReservationService).
 * Nullable : NULL = mouvement actif, renseigné = annulé par le mouvement référencé.
 * restrictOnDelete (jamais cascade) : un contre-mouvement ne doit jamais pouvoir disparaître
 * silencieusement en emportant la référence à ce qu'il annule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->foreignUlid('annule_par_id')->nullable()->after('created_by')
                ->constrained('mouvements_stock')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropForeign(['annule_par_id']);
            $table->dropColumn('annule_par_id');
        });
    }
};
