<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paie_variables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('paie_ligne_id')->constrained('paie_lignes')->cascadeOnDelete();
            $table->string('type', 30); // prime|avance|retenue|absence|autre_gain|autre_deduction
            $table->string('libelle', 150);
            $table->decimal('montant', 15, 2);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('paie_ligne_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paie_variables');
    }
};
