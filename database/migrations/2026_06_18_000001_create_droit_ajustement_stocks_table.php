<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('droit_ajustement_stocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            // 'toutes' | 'sites_specifiques'
            $table->string('perimetre')->default('toutes');
            // JSON array of site ULIDs, null when perimetre = 'toutes'
            $table->json('sites')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'role_name']);
            $table->index(['organization_id', 'is_actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('droit_ajustement_stocks');
    }
};
