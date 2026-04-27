<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commande_vente_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->foreignUlid('produit_id')->constrained('produits')->restrictOnDelete();
            $table->integer('qte');
            $table->decimal('prix_usine_snapshot', 12, 2);
            $table->decimal('prix_vente_snapshot', 12, 2);
            $table->decimal('total_ligne', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_vente_lignes');
    }
};
