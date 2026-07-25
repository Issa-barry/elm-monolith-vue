<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('droit_creation_depenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            // 'toutes_agences' | 'agences_selectionnees'
            $table->string('perimetre')->default('toutes_agences');
            // JSON array of site ULIDs, null when perimetre = 'toutes_agences'
            $table->json('sites')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->boolean('peut_valider')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'role_name']);
            $table->index(['organization_id', 'is_actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('droit_creation_depenses');
    }
};
