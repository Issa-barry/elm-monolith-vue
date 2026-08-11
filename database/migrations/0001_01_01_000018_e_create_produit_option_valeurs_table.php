<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_option_valeurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('produit_option_id')->constrained('produit_options')->cascadeOnDelete();
            $table->string('valeur');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'produit_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_option_valeurs');
    }
};
