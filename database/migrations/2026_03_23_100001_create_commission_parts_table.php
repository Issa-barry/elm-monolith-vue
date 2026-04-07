<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une ligne par bénéficiaire (livreur membre équipe + propriétaire)
        Schema::create('commission_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_vente_id')->constrained('commissions_ventes')->cascadeOnDelete();

            // Bénéficiaire
            $table->string('type_beneficiaire', 20);                                     // livreur | proprietaire
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->foreignId('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            $table->string('beneficiaire_nom');                                           // snapshot nom

            // Taux et montants (snapshot figé à la génération)
            $table->decimal('taux_commission', 5, 2);
            $table->decimal('montant_brut', 15, 2);
            $table->decimal('frais_supplementaires', 15, 2)->default(0);                 // déduit uniquement du propriétaire
            $table->decimal('montant_net', 15, 2);                                        // brut - frais (>= 0)

            // Suivi versements
            $table->decimal('montant_verse', 15, 2)->default(0);
            $table->string('statut', 20)->default('en_attente');                         // en_attente|partielle|versee

            $table->timestamps();

            $table->index(['commission_vente_id', 'type_beneficiaire']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_parts');
    }
};
