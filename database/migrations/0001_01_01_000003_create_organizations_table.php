<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 20)->unique();
            $table->char('siret', 14)->nullable()->unique();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            // Compteur de séquence par organisation pour les références produit
            // (ProduitVariante.sku) — style numéro d'article IKEA : un entier stable,
            // qui n'encode ni le nom, ni la catégorie, ni le prix, ni la date.
            // Porté par l'organisation (pas une table globale) pour garantir une
            // numérotation indépendante par tenant, cohérente avec l'unicité
            // ['organization_id', 'sku'] de produit_variantes.
            $table->unsignedInteger('next_produit_reference')->default(100001);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
