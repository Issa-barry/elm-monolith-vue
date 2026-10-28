<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des types de primes et d'avantages (lot 2 RH, docs/primes-avantages.md, ADR 0038).
 * Nouvelle table uniquement : aucune donnée existante n'est touchée. Les attributions aux salariés
 * (lot 3) référenceront cette table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_remuneration', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->text('description')->nullable();
            $table->string('categorie', 30);
            $table->string('mode_calcul', 30);
            $table->string('periodicite', 20);
            $table->string('nature_montant', 20);
            $table->boolean('proratisable')->default(false);
            $table->boolean('cumul_autorise')->default(false);
            $table->decimal('montant_defaut', 15, 2)->nullable();
            $table->decimal('taux_defaut', 5, 2)->nullable();
            $table->date('date_debut_application')->nullable();
            $table->date('date_fin_application')->nullable();
            $table->string('statut', 20)->default('a_valider');
            $table->foreignUlid('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'libelle']);
            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types_remuneration');
    }
};
