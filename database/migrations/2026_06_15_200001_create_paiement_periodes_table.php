<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_periodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('reference', 20);
            $table->string('type', 20);
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 20)->default('brouillon');
            $table->string('calcul_hash', 32)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->text('observations')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'type', 'statut']);
            $table->index(['organization_id', 'date_debut', 'date_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_periodes');
    }
};
