<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employe_id')->constrained('employes')->cascadeOnDelete();
            $table->string('type_contrat', 10);
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->decimal('salaire_base', 15, 2)->nullable();
            $table->string('statut_contrat', 20)->default('actif');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrats');
    }
};
