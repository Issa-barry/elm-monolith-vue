<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions_logistiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfert_logistique_id')->unique()->constrained('transferts_logistiques')->cascadeOnDelete();
            $table->foreignId('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->string('base_calcul'); // forfait | par_pack | par_km
            $table->decimal('valeur_base', 12, 2);
            $table->integer('quantite_reference')->nullable(); // packs livrés ou km
            $table->decimal('montant_total', 12, 2)->default(0);
            $table->string('statut')->default('en_attente'); // en_attente | partiellement_verse | verse
            $table->timestamps();

            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_logistiques');
    }
};
