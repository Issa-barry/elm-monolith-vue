<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Élargit `mouvements_fonds.nature` de 20 à 40 caractères : la nature `approvisionnement_caisse`
 * (ADR 0018) en compte 24 et MySQL refusait l'insertion (« Data too long for column 'nature' »).
 * Élargissement seul, les valeurs existantes et le défaut sont conservés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->string('nature', 40)->default('inter_sites')->change();
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            $table->string('nature', 20)->default('inter_sites')->change();
        });
    }
};
