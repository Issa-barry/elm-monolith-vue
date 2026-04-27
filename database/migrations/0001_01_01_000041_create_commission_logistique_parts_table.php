<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_logistique_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commission_logistique_id')->constrained('commissions_logistiques')->cascadeOnDelete();
            $table->string('type_beneficiaire');
            $table->foreignUlid('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->foreignUlid('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            $table->string('beneficiaire_nom');
            $table->decimal('taux_commission', 5, 2);
            $table->decimal('montant_brut', 12, 2);
            $table->decimal('frais_supplementaires', 12, 2)->default(0);
            $table->string('type_frais')->nullable();
            $table->string('commentaire_frais')->nullable();
            $table->decimal('montant_net', 12, 2)->default(0);
            $table->decimal('montant_verse', 12, 2)->default(0);
            $table->string('statut')->default('en_attente');
            $table->date('earned_at')->nullable();
            $table->date('unlock_at')->nullable();
            $table->string('periode', 12)->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['commission_logistique_id', 'type_beneficiaire', 'livreur_id', 'proprietaire_id'],
                'parts_commission_beneficiaire_unique'
            );
            $table->index(['commission_logistique_id', 'statut'], 'comm_log_parts_id_statut_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_logistique_parts');
    }
};
