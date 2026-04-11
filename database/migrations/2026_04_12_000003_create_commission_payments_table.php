<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements de commissions logistiques multi-transferts.
 *
 * Un paiement est émis vers UN bénéficiaire (livreur OU propriétaire)
 * pour UN véhicule, et couvre potentiellement N parts de N transferts.
 * L'allocation détaillée se trouve dans commission_payment_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicule_id')->constrained('vehicules')->cascadeOnDelete();

            // Bénéficiaire — deux colonnes nullable, l'une d'elles sera renseignée
            $table->foreignId('livreur_id')
                ->nullable()
                ->constrained('livreurs')
                ->nullOnDelete();
            $table->foreignId('proprietaire_id')
                ->nullable()
                ->constrained('proprietaires')
                ->nullOnDelete();

            // Copie dénormalisée pour l'affichage sans join
            $table->string('beneficiary_type');   // livreur | proprietaire
            $table->string('beneficiary_nom');

            $table->decimal('montant', 12, 2);
            $table->string('mode_paiement');       // especes | virement | cheque | mobile_money
            $table->text('note')->nullable();
            $table->date('paid_at');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
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
