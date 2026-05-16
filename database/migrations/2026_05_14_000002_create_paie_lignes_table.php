<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paie_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('paie_periode_id')->constrained('paie_periodes')->cascadeOnDelete();
            $table->foreignUlid('employe_id')->constrained('employes')->cascadeOnDelete();
            $table->ulid('contrat_id')->nullable(); // snapshot FK, nullable si pas de contrat
            $table->foreign('contrat_id')->references('id')->on('contrats')->nullOnDelete();

            // Snapshot du salaire de base calculé (avec prorata éventuel)
            $table->decimal('salaire_base', 15, 2)->default(0);
            $table->tinyInteger('jours_travailles')->unsigned()->default(0);
            $table->tinyInteger('jours_periode')->unsigned()->default(0);

            // Gains
            $table->decimal('total_primes', 15, 2)->default(0);
            $table->decimal('total_autres_gains', 15, 2)->default(0);

            // Déductions
            $table->decimal('total_avances', 15, 2)->default(0);
            $table->decimal('total_retenues', 15, 2)->default(0);
            $table->decimal('total_absences', 15, 2)->default(0);
            $table->decimal('total_autres_deductions', 15, 2)->default(0);

            // Totaux calculés
            $table->decimal('brut', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('deja_paye', 15, 2)->default(0);
            $table->decimal('reste_a_payer', 15, 2)->default(0);

            $table->string('statut', 30)->default('en_attente'); // en_attente|calcule|partiellement_paye|paye
            $table->timestamps();

            $table->unique(['paie_periode_id', 'employe_id'], 'paie_lignes_periode_employe_unique');
            $table->index('paie_periode_id');
            $table->index('employe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paie_lignes');
    }
};
