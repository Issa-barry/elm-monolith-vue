<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depense_imputations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('depense_id')->constrained('depenses')->cascadeOnDelete();

            // salaire | commission_livreur | commission_proprietaire
            $table->string('imputation_type', 30);
            // employe | livreur | proprietaire (toujours résolu, jamais vehicule)
            $table->string('beneficiaire_type', 20);
            $table->ulid('beneficiaire_id');

            $table->decimal('montant', 12, 2);

            // quinzaine | mensuelle
            $table->string('periode_type', 20);
            $table->date('periode_debut');
            $table->date('periode_fin')->nullable();

            // en_attente | impute | annule
            $table->string('statut', 20)->default('en_attente');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['beneficiaire_type', 'beneficiaire_id', 'statut'], 'di_ben_statut_idx');
            $table->index('depense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depense_imputations');
    }
};
