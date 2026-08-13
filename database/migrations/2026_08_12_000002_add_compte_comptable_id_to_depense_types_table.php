<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet à l'expert-comptable de rattacher chaque type de dépense (carburant,
 * loyer, fournitures...) à son compte de charge SYSCOHADA — nécessaire car
 * aucun mapping generique unique ne peut deviner le bon compte de charge
 * pour un depense_type donné (contrairement aux autres événements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->foreignUlid('compte_comptable_id')
                ->nullable()
                ->after('categorie')
                ->constrained('comptes_comptables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compte_comptable_id');
        });
    }
};
