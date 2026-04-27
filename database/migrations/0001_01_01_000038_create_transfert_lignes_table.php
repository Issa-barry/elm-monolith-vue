<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfert_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfert_logistique_id')->constrained('transferts_logistiques')->cascadeOnDelete();
            $table->foreignUlid('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->integer('quantite_demandee')->nullable();
            $table->integer('quantite_chargee')->nullable();
            $table->integer('quantite_recue')->nullable();
            $table->text('notes')->nullable();
            $table->string('ecart_type', 30)->nullable();
            $table->text('ecart_motif')->nullable();
            $table->timestamps();

            $table->unique(['transfert_logistique_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfert_lignes');
    }
};
