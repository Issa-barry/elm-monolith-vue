<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_regles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('processus_id')->constrained('commission_processus')->cascadeOnDelete();
            $table->string('libelle');
            $table->string('scope_type', 20);
            // Polymorphe léger (pointe vers categories / produits / produit_variantes selon
            // scope_type, ou NULL si scope_type = global) — pas de contrainte FK native
            // possible sur une colonne qui référence des tables différentes.
            $table->ulid('scope_id')->nullable();
            $table->string('cible_type', 40);
            $table->string('mode', 20);
            $table->string('unite_calcul', 30);
            // Nullable : ignoré quand unite_calcul = marge_operation (pont Phase 1, la base
            // est la marge réelle de l'opération, pas un taux unitaire fixe).
            $table->decimal('montant', 12, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignUlid('remplace_regle_id')->nullable()->constrained('commission_regles')->nullOnDelete();
            $table->string('statut', 20)->default('active');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'processus_id', 'cible_type', 'scope_type', 'scope_id'], 'comm_regles_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_regles');
    }
};
