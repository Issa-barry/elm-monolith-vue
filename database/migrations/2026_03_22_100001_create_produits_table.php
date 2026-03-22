<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('nom');
            $table->string('code_interne', 50)->nullable()->unique();
            $table->string('code_fournisseur', 100)->nullable()->index();

            // Prix en GNF (entiers)
            $table->unsignedBigInteger('prix_usine')->nullable();
            $table->unsignedBigInteger('prix_vente')->nullable();
            $table->unsignedBigInteger('prix_achat')->nullable();
            $table->unsignedBigInteger('cout')->nullable();

            // Stock (sera déplacé dans une table Stock séparée avec les Sites)
            $table->unsignedInteger('qte_stock')->default(0);
            $table->unsignedInteger('seuil_alerte_stock')->nullable();

            // Catégorisation
            $table->string('type')->default('materiel')->index();
            $table->string('statut')->default('actif')->index();

            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('is_critique')->default(false)->index();
            $table->timestamp('last_stockout_notified_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Traçabilité
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['statut', 'type']);
            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
