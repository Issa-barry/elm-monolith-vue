<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports_flotte', function (Blueprint $table) {
            // 'flotte' (véhicules + livreurs, historique) | 'livreurs' (véhicules
            // déjà existants — cf. App\Enums\TypeImportFlotte). Placé après
            // 'statut' pour rester avec les autres colonnes de qualification.
            $table->string('type', 20)->default('flotte')->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('imports_flotte', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
