<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache un produit à un groupe de capacité (ex: "Sachets", "Bouteilles") — indépendant de
 * sa Categorie catalogue. Nullable : un produit qui ne participe à aucun contrôle de capacité
 * (pas transporté par véhicule, ou organisation n'utilisant pas cette fonctionnalité) n'en a
 * simplement pas besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->foreignUlid('groupe_capacite_id')->nullable()
                ->after('categorie_id')
                ->constrained('groupes_capacite')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('groupe_capacite_id');
        });
    }
};
