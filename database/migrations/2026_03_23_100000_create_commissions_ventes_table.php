<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entête commission : une par commande livrée
        Schema::create('commissions_ventes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->foreignId('vehicule_id')->constrained('vehicules')->restrictOnDelete();
            $table->decimal('montant_commande', 15, 2);          // snapshot total commande
            $table->decimal('montant_commission_totale', 15, 2); // prix_vente - prix_usine
            $table->decimal('montant_verse', 15, 2)->default(0); // agrégat dénormalisé
            $table->string('statut')->default('en_attente');      // en_attente|partielle|versee|annulee
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_ventes');
    }
};
