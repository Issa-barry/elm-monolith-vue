<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_variantes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('produit_id')->constrained('produits')->cascadeOnDelete();

            // Référence — SKU, déplacé depuis produits.code_interne (nommé clairement, source unique)
            $table->string('sku', 50)->nullable();
            $table->string('code_barres', 100)->nullable();
            $table->string('code_fournisseur', 100)->nullable()->index();

            // Prix / coût — déplacés depuis produits
            $table->unsignedBigInteger('prix_usine')->nullable();
            $table->unsignedBigInteger('prix_vente')->nullable();
            $table->unsignedBigInteger('prix_achat')->nullable();
            $table->unsignedBigInteger('cout')->nullable();
            $table->unsignedInteger('seuil_alerte_stock')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // Hash des valeurs d'option triées ('default' pour la variante par défaut) —
            // garantit en DB l'absence de doublon de combinaison sur un même produit.
            $table->string('combo_hash', 64)->default('default');

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'code_barres']);
            $table->unique(['produit_id', 'combo_hash']);
            $table->index(['organization_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_variantes');
    }
};
