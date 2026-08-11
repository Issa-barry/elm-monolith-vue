<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_variante_valeurs', function (Blueprint $table) {
            // Pure table pivot (peuplée via belongsToMany()->attach(), pas un modèle Eloquent
            // à part entière) : pas d'id ULID — HasUlids ne s'applique qu'aux vrais modèles.
            $table->foreignUlid('produit_variante_id')->constrained('produit_variantes')->cascadeOnDelete();
            // Dénormalisé depuis produit_option_valeurs.produit_option_id : permet à la contrainte
            // unique ci-dessous de garantir qu'une variante ne combine jamais deux valeurs de la même option.
            $table->foreignUlid('produit_option_id')->constrained('produit_options')->cascadeOnDelete();
            $table->foreignUlid('produit_option_valeur_id')->constrained('produit_option_valeurs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['produit_variante_id', 'produit_option_id'], 'pvv_variante_option_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_variante_valeurs');
    }
};
