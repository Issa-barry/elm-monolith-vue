<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferts_logistiques', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('numero')->nullable()->index();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique();
            $table->char('code_confirmation', 3)->nullable();
            $table->foreignUlid('site_source_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('site_destination_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->foreignUlid('equipe_livraison_id')->nullable()->constrained('equipes_livraison')->nullOnDelete();
            $table->string('statut')->default('brouillon');
            $table->date('date_depart_prevue')->nullable();
            $table->date('date_depart_reelle')->nullable();
            $table->date('date_arrivee_prevue')->nullable();
            $table->date('date_arrivee_reelle')->nullable();
            $table->text('notes')->nullable();
            $table->string('validation_reception')->nullable();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('validation_motif')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferts_logistiques');
    }
};
