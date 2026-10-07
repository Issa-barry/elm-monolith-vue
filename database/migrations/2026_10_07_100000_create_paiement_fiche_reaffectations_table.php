<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changement d'agence d'une fiche livreur/propriétaire non payée (ADR 0020) : une ligne par
 * changement, source des deux pièces comptables qui déplacent le reste dû vers la nouvelle agence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paiement_fiche_reaffectations')) {
            return;
        }

        Schema::create('paiement_fiche_reaffectations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Conservée même si la fiche disparaît : les pièces comptables pointent sur cette ligne.
            $table->foreignUlid('fiche_id')->nullable()->constrained('paiement_fiches')->nullOnDelete();
            $table->foreignUlid('site_origine_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('site_destination_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->decimal('montant', 15, 2);
            $table->timestamps();

            $table->index(['fiche_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_fiche_reaffectations');
    }
};
