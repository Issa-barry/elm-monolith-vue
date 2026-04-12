<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements_commissions_ventes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('type_beneficiaire');                                        // livreur | proprietaire
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->foreignId('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            $table->string('beneficiaire_nom');
            $table->decimal('montant', 15, 2);
            $table->string('mode_paiement');
            $table->text('note')->nullable();
            $table->date('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'type_beneficiaire', 'livreur_id'], 'pcv_org_type_livreur_idx');
            $table->index(['organization_id', 'type_beneficiaire', 'proprietaire_id'], 'pcv_org_type_proprio_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_commissions_ventes');
    }
};
