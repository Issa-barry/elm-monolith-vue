<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            // restrictOnDelete (au lieu de cascade avant refonte) : évite qu'une suppression de
            // variante efface silencieusement son historique de mouvements de stock.
            $table->foreignUlid('produit_variante_id')->constrained('produit_variantes')->restrictOnDelete();
            $table->string('type');
            $table->integer('quantite');
            $table->unsignedInteger('stock_avant')->nullable();
            $table->unsignedInteger('stock_apres')->nullable();
            $table->nullableUlidMorphs('source');
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'site_id', 'produit_variante_id'], 'mouvements_stock_org_site_variante_idx');
            $table->index(['organization_id', 'created_at']);
            $table->unique(['source_type', 'source_id', 'site_id', 'type'], 'mouvements_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
