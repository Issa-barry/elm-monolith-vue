<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les paiements de commissions logistiques sont maintenant globaux par livreur
 * (multi-véhicules). vehicule_id devient nullable pour les nouveaux paiements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payments', function (Blueprint $table) {
            $table->foreignId('vehicule_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('commission_payments', function (Blueprint $table) {
            $table->foreignId('vehicule_id')
                ->nullable(false)
                ->change();
        });
    }
};
