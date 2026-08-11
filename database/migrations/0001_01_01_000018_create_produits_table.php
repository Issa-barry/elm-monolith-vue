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
            $table->string('nom');
            $table->string('type', 30)->default('materiel')->index();
            $table->string('statut', 30)->default('actif')->index();
            $table->text('description')->nullable();

            // Cache dénormalisé, resynchronisé depuis variante_stocks (somme toutes variantes/sites)
            $table->unsignedInteger('qte_stock')->default(0);
            $table->boolean('is_alerte')->default(false)->index();
            $table->timestamp('last_stockout_notified_at')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['statut', 'type']);
            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'categorie_id']);
            $table->index(['organization_id', 'fournisseur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
