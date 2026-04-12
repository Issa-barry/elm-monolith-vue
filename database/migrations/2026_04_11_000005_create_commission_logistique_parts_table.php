<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_logistique_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_logistique_id')->constrained('commissions_logistiques')->cascadeOnDelete();
            $table->string('type_beneficiaire'); // proprietaire | livreur
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->string('beneficiaire_nom');
            $table->decimal('taux_commission', 5, 2); // pourcentage ex: 15.00
            $table->decimal('montant_brut', 12, 2);
            $table->decimal('montant_verse', 12, 2)->default(0);
            $table->string('statut')->default('en_attente'); // en_attente | partiellement_verse | verse
            $table->timestamps();

            $table->unique(
                ['commission_logistique_id', 'type_beneficiaire', 'livreur_id'],
                'parts_commission_unique'
            );
            $table->index(['commission_logistique_id', 'statut'], 'comm_log_parts_id_statut_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_logistique_parts');
    }
};
