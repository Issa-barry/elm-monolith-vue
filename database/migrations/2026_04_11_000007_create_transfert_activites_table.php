<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfert_activites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfert_logistique_id')->constrained('transferts_logistiques')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');           // creation, chargement_demarre, chargement_valide, reception_validee, cloture, annule, commission_generee, versement_effectue
            $table->json('details')->nullable(); // données contextuelles (montant, mode_paiement, etc.)
            $table->timestamps();

            $table->index(['transfert_logistique_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfert_activites');
    }
};
