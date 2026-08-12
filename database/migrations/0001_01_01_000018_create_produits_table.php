<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
            // Fournisseur principal (0 ou 1) — entité dédiée (table fournisseurs), distincte de
            // Prestataire (main-d'œuvre externe : machiniste, mécanicien, consultant).
            // nullOnDelete plutôt que restrict : supprimer un fournisseur ne doit jamais bloquer
            // sur les produits qui le référençaient, juste les détacher.
            $table->foreignUlid('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            // restrict (pas nullOnDelete) : un type ne peut être supprimé tant qu'il est utilisé
            // par au moins un produit (cf. ProduitTypeController::destroy()) — la contrainte DB
            // est le dernier filet de sécurité derrière ce contrôle applicatif.
            $table->foreignUlid('produit_type_id')->constrained('produit_types')->restrictOnDelete();
            $table->string('nom');
            $table->string('statut', 30)->default('actif')->index();
            $table->text('description')->nullable();

            // Cache dénormalisé, resynchronisé depuis variante_stocks (somme toutes variantes/sites)
            $table->unsignedInteger('qte_stock')->default(0);

            // ── Alerte de stock faible (uniquement pertinent si produit_type.gere_stock) ────
            // Choix obligatoire à la création (jamais de valeur implicite) : voulez-vous être
            // alerté quand le stock devient faible ?
            $table->boolean('alerte_stock_active')->default(false);
            // null = hérite du seuil par défaut de l'organisation (parametres.seuil_stock_faible).
            // Ce seuil s'applique uniformément à toutes les variantes du produit, évalué
            // individuellement pour chaque couple variante × site (cf. StockStatutService).
            $table->unsignedInteger('seuil_alerte_stock')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['statut', 'produit_type_id']);
            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'categorie_id']);
            $table->index(['organization_id', 'fournisseur_id']);
            $table->index(['organization_id', 'produit_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
