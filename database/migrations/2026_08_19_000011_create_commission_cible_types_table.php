<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_cible_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('libelle');
            $table->string('periodicite', 20);
            $table->timestamps();

            // organization_id NULL = référentiel par défaut applicatif (non spécifique à une
            // organisation) — l'unicité doit donc tolérer plusieurs lignes NULL pour des codes
            // différents mais jamais deux fois le même (organization_id, code).
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_cible_types');
    }
};
