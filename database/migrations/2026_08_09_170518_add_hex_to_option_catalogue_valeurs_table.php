<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_catalogue_valeurs', function (Blueprint $table) {
            // Aide visuelle uniquement (pastille de couleur façon Shopify) — jamais utilisé
            // pour de la logique métier, uniquement pour les valeurs d'options de type couleur.
            $table->string('hex', 7)->nullable()->after('valeur');
        });
    }

    public function down(): void
    {
        Schema::table('option_catalogue_valeurs', function (Blueprint $table) {
            $table->dropColumn('hex');
        });
    }
};
