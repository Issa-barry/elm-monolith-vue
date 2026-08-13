<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_catalogue_valeurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('option_catalogue_id')->constrained('option_catalogues')->cascadeOnDelete();
            $table->string('valeur');
            // Aide visuelle uniquement (pastille de couleur façon Shopify) — jamais utilisé
            // pour de la logique métier, uniquement pour les valeurs d'options de type couleur.
            $table->string('hex', 7)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['option_catalogue_id', 'valeur']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_catalogue_valeurs');
    }
};
