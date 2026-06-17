<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_fiches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('periode_id')->constrained('paiement_periodes')->cascadeOnDelete();
            $table->string('reference', 20);
            $table->string('beneficiaire_type', 20);
            $table->ulid('beneficiaire_id');
            $table->string('beneficiaire_nom', 255);
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->decimal('montant_brut', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('montant_net', 15, 2)->default(0);
            $table->decimal('montant_paye', 15, 2)->default(0);
            $table->string('statut', 20)->default('a_payer');
            $table->string('mode_paiement', 20)->nullable();
            $table->date('date_paiement')->nullable();
            $table->foreignUlid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_path')->nullable();
            $table->text('commentaires')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['periode_id', 'beneficiaire_type', 'beneficiaire_id'], 'pf_periode_ben_unique');
            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'beneficiaire_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_fiches');
    }
};
