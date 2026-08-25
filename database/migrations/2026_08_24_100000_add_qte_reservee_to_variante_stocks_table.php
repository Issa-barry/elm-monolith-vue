<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur dénormalisé de la quantité réservée (commandes vente confirmées, pas encore
 * chargées) — StockReservation reste la preuve métier, cette colonne n'est qu'un total
 * rapide pour éviter un SUM() sur chaque calcul de disponible (cf. MouvementStockService::
 * quantiteDisponible(), StockReservationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variante_stocks', function (Blueprint $table) {
            $table->integer('qte_reservee')->default(0)->after('qte_stock');
        });
    }

    public function down(): void
    {
        Schema::table('variante_stocks', function (Blueprint $table) {
            $table->dropColumn('qte_reservee');
        });
    }
};
