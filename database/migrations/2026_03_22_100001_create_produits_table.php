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
            $table->unsignedBigInteger('organization_id');

            $table->string('nom');
            $table->string('code_interne', 50)->nullable()->unique();
            $table->string('code_fournisseur', 100)->nullable()->index();

            $table->unsignedBigInteger('prix_usine')->nullable();
            $table->unsignedBigInteger('prix_vente')->nullable();
            $table->unsignedBigInteger('prix_achat')->nullable();
            $table->unsignedBigInteger('cout')->nullable();

            $table->unsignedInteger('qte_stock')->default(0);
            $table->unsignedInteger('seuil_alerte_stock')->nullable();

            $table->string('type')->default('materiel')->index();
            $table->string('statut')->default('actif')->index();

            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->boolean('is_critique')->default(false)->index();
            $table->timestamp('last_stockout_notified_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['statut', 'type']);
            $table->index(['organization_id', 'statut']);
        });

        // FK séparées pour éviter les problèmes MySQL avec ALTER TABLE dans le même batch
        Schema::table('produits', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
