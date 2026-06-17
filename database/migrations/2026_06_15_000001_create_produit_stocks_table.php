<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_stocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedInteger('qte_stock')->default(0);
            $table->unsignedInteger('seuil_alerte_stock')->nullable();
            $table->boolean('is_alerte')->default(false);
            $table->timestamps();

            $table->unique(['produit_id', 'site_id']);
            $table->index(['organization_id', 'site_id']);
            $table->index(['organization_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_stocks');
    }
};
