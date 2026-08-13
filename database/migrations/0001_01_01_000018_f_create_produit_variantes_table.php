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

            // Référence interne — identifiant stable de l'article (style numéro d'article IKEA,
            // généré automatiquement par Organization::prochaineReferenceProduit(), jamais
            // dérivé du nom/catégorie/prix). Toujours attribuée à la création : obligatoire.
            $table->string('sku', 50);
            // Code-barres scannable (EAN/UPC/Code128...) — facultatif, distinct de la référence.
            // Pas de champ "code_fournisseur" séparé : un code-barres fourni par le fournisseur
            // va directement ici, il n'y a qu'une seule notion de "code externe" par article.
            $table->string('code_barres', 100)->nullable();

            // Prix / coût — déplacés depuis produits
            $table->unsignedBigInteger('prix_usine')->nullable();
            $table->unsignedBigInteger('prix_vente')->nullable();
            $table->unsignedBigInteger('prix_achat')->nullable();
            $table->unsignedBigInteger('cout')->nullable();

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
