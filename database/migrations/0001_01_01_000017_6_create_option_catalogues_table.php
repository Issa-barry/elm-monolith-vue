<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliothèque d'options réutilisables (ex: "Couleur", "Pointure") — indépendante de
 * produit_options : une modification ici ne doit jamais impacter les options déjà
 * attachées à un produit existant (produit_options en garde une copie autonome, cf.
 * VarianteService). Sert uniquement de modèle/suggestion au moment de la création.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_catalogues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            // Option de bibliothèque proposée par défaut (Couleur/Taille/Pointure), seedée
            // pour chaque organisation. Non supprimable (cf. OptionCatalogueController::destroy()),
            // mais ses valeurs proposées restent librement ajoutables/supprimables.
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_catalogues');
    }
};
