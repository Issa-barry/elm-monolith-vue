<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table append-only : chaque tentative de génération (réussie ou non) est une
     * nouvelle ligne, jamais mise à jour ni supprimée. Le statut de génération
     * affiché pour une opération est une vue calculée (dernière tentative), jamais
     * un champ stocké séparément — cf. CommissionGenerationAttempt::statutPour().
     */
    public function up(): void
    {
        Schema::create('commission_generation_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Polymorphe léger (CommandeVente / TransfertLogistique...).
            $table->string('source_type', 100);
            $table->ulid('source_id');
            $table->foreignUlid('processus_id')->constrained('commission_processus')->cascadeOnDelete();
            $table->string('statut', 20);
            $table->text('motif_erreur')->nullable();
            $table->json('detail_erreur')->nullable();
            $table->string('declenchee_par', 20);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['source_type', 'source_id', 'processus_id', 'created_at'], 'comm_gen_attempts_source_idx');
            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_generation_attempts');
    }
};
