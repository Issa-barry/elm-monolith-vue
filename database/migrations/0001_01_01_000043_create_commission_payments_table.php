<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->foreignUlid('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->foreignUlid('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            $table->string('beneficiary_type');
            $table->string('beneficiary_nom');
            $table->decimal('montant', 12, 2);
            $table->string('mode_paiement');
            $table->text('note')->nullable();
            $table->date('paid_at');
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'vehicule_id', 'beneficiary_type'], 'cpay_org_veh_type_idx');
            $table->index(['organization_id', 'paid_at'], 'cpay_org_paid_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payments');
    }
};
