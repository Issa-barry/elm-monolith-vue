<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            // Slug technique stable (ex: 'materiel', 'fabricable') — sert de repère pour les
            // seeders/imports, distinct du libellé affiché (nom) que l'organisation peut renommer
            // librement sans casser les règles qui s'appuient sur ce code.
            $table->string('code', 50);

            // ── Capacités structurelles (remplacent l'ancien enum ProduitType figé) ─────────
            $table->boolean('gere_stock')->default(true);
            $table->boolean('vendable')->default(true);
            $table->boolean('achetable')->default(false);
            $table->boolean('prix_achat_requis')->default(false);
            $table->boolean('prix_usine_requis')->default(false);
            $table->boolean('prix_vente_requis')->default(false);
            // Prix de référence pour le garde-fou anti-vente-à-perte (prix_vente > ce champ),
            // non contournable — cf. ProduitType::champPrixReference() historique. Doit être un
            // des prix requis ci-dessus ou null (aucune règle de marge pour ce type).
            $table->string('champ_prix_reference', 20)->nullable();

            $table->string('statut', 20)->default('actif');
            $table->unsignedInteger('position')->default(0);

            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_types');
    }
};
