<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve métier d'une réservation de stock (commande vente confirmée, en attente de
 * chargement) — variante_stocks.qte_reservee n'est qu'un compteur dérivé de ces lignes,
 * jamais la source de vérité. Une réservation est créée ACTIVE à la confirmation d'une
 * commande, puis soit CONSOMMEE (chargement validé — le stock physique est décrémenté),
 * soit LIBEREE (annulation avant chargement) — jamais supprimée, pour garder une trace
 * complète (cf. MouvementStock, même principe d'immuabilité).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('produit_variante_id')->constrained('produit_variantes')->restrictOnDelete();
            $table->unsignedInteger('quantite');
            $table->string('statut');
            $table->nullableUlidMorphs('source');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'site_id', 'produit_variante_id', 'statut'], 'stock_reservations_org_site_variante_statut_idx');
            // Une ligne de commande n'est jamais réservée deux fois : au plus une réservation
            // active par (source, site) sur toute sa durée de vie (BROUILLON n'en crée aucune,
            // ANNULEE est terminale — jamais de re-confirmation après annulation).
            $table->unique(['source_type', 'source_id', 'site_id'], 'stock_reservations_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
