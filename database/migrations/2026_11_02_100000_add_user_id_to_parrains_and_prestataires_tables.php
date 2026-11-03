<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte rattaché à un parrain ou à un consultant personne physique (ADR 0050). Toujours vide à la
 * migration : aucun rattachement en masse, seul RattachementCompteService pose cette valeur, sur
 * action explicite d'un responsable au back-office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parrains', function (Blueprint $table) {
            $table->foreignUlid('user_id')->nullable()->after('personne_id')->constrained()->nullOnDelete();
        });

        Schema::table('prestataires', function (Blueprint $table) {
            $table->foreignUlid('user_id')->nullable()->after('entreprise_tierce_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prestataires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('parrains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
