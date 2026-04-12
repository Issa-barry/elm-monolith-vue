<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfert_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfert_logistique_id')->constrained('transferts_logistiques')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->integer('quantite_demandee');
            $table->integer('quantite_chargee')->nullable();
            $table->integer('quantite_recue')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['transfert_logistique_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfert_lignes');
    }
};
