<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            // Parrainage (phase 1, sans commission ni historique — cf. docs/parrainage-vehicule.md) :
            // pointeur simple vers le parrain actuel, sur le même modèle que proprietaire_id.
            $table->foreignUlid('parrain_id')
                ->nullable()
                ->after('proprietaire_id')
                ->constrained('parrains')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parrain_id');
        });
    }
};
