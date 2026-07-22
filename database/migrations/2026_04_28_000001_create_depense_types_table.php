<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depense_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('libelle', 100);
            $table->string('description')->nullable();
            $table->string('categorie', 20)->default('interne');
            $table->boolean('commentaire_obligatoire')->default(false);
            $table->boolean('justificatif_obligatoire')->default(false);
            $table->string('type_paie')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'categorie', 'is_active'], 'dt_org_cat_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depense_types');
    }
};
