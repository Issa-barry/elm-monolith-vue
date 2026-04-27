<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commission_vente_id')->constrained('commissions_ventes')->cascadeOnDelete();
            $table->string('type_beneficiaire', 20);
            $table->foreignUlid('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->foreignUlid('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            $table->string('beneficiaire_nom');
            $table->string('role', 50)->nullable();
            $table->decimal('taux_commission', 5, 2);
            $table->decimal('montant_brut', 15, 2);
            $table->decimal('montant_net', 15, 2);
            $table->decimal('montant_verse', 15, 2)->default(0);
            $table->decimal('frais_supplementaires', 15, 2)->default(0);
            $table->string('type_frais', 20)->nullable();
            $table->string('commentaire_frais', 150)->nullable();
            $table->string('statut', 20)->default('en_attente');
            $table->timestamps();

            $table->index(['commission_vente_id', 'type_beneficiaire']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_parts');
    }
};
