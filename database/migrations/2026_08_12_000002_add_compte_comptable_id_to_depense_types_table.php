<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet à l'expert-comptable de rattacher chaque type de dépense (carburant,
 * loyer, fournitures...) à son compte de charge SYSCOHADA — nécessaire car
 * aucun mapping generique unique ne peut deviner le bon compte de charge
 * pour un depense_type donné (contrairement aux autres événements).
 *
 * Reste une migration séparée (pas fusionnée dans create_depense_types_table,
 * antérieure de plusieurs mois) : depense_types est créée bien avant
 * compta_comptes dans l'ordre des migrations, la FK ne peut donc être posée
 * qu'ici, une fois compta_comptes existante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->foreignUlid('compte_comptable_id')
                ->nullable()
                ->after('categorie')
                ->constrained('compta_comptes')
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
