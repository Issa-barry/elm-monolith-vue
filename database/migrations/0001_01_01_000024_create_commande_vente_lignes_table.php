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

            // Quantités du workflow
            $table->integer('quantite_demandee');         // Qté commandée (saisie à la création)
            $table->integer('quantite_chargee')->nullable(); // Qté effectivement chargée
            $table->integer('quantite_livree')->nullable();  // Qté effectivement livrée / reçue côté client

            // Écarts
            $table->string('type_ecart', 30)->nullable();   // TypeEcartLogistique : conforme|casse|perte|surplus|manquant
            $table->text('commentaire_ecart')->nullable();

            // Snapshot prix au moment de la création
            $table->decimal('prix_usine_snapshot', 12, 2);
            $table->decimal('prix_vente_snapshot', 12, 2);
            $table->decimal('total_ligne', 12, 2);

            $table->timestamps();

            $table->unique(['commande_vente_id', 'produit_id'], 'cv_lignes_commande_produit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_vente_lignes');
    }
};
