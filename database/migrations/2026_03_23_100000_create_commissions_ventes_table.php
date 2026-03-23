<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions_ventes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->foreignId('vehicule_id')->constrained('vehicules')->restrictOnDelete();
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->string('livreur_nom')->nullable();          // snapshot
            $table->decimal('taux_commission', 5, 2);           // snapshot %
            $table->decimal('montant_commande', 15, 2);         // snapshot
            $table->decimal('montant_commission', 15, 2);       // calculé
            $table->decimal('montant_verse', 15, 2)->default(0);
            $table->string('statut')->default('en_attente');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_ventes');
    }
};
