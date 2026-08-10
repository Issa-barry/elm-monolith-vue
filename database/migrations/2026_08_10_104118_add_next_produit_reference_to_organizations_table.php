<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compteur de séquence par organisation pour les références produit
     * (ProduitVariante.sku) — style numéro d'article IKEA : un entier stable,
     * qui n'encode ni le nom, ni la catégorie, ni le prix, ni la date.
     * Porté par l'organisation (pas une table globale) pour garantir une
     * numérotation indépendante par tenant, cohérente avec l'unicité
     * ['organization_id', 'sku'] de produit_variantes.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('next_produit_reference')->default(100001);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('next_produit_reference');
        });
    }
};
