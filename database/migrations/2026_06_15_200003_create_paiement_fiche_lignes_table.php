<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_fiche_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('fiche_id')->constrained('paiement_fiches')->cascadeOnDelete();
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 26)->nullable();
            $table->string('type_ligne', 30);
            $table->string('libelle', 255);
            $table->decimal('montant', 15, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['fiche_id', 'type_ligne']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_fiche_lignes');
    }
};
