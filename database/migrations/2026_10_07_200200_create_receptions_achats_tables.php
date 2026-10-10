<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réceptions de bons de commande fournisseurs (ADR 0021) : une commande peut être reçue en
 * plusieurs fois. Chaque ligne de réception fige son coût unitaire et pointe vers le mouvement de
 * stock qu'elle a créé sur l'agence de la commande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receptions_achats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('commande_achat_id')->constrained('commandes_achats')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites');
            $table->string('reference')->unique();
            $table->unsignedInteger('numero')->nullable();
            $table->date('date_reception');
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'date_reception']);
        });

        Schema::create('reception_achat_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reception_achat_id')->constrained('receptions_achats')->cascadeOnDelete();
            $table->foreignUlid('commande_achat_ligne_id')->constrained('commande_achat_lignes')->cascadeOnDelete();
            $table->foreignUlid('variante_id')->nullable()->constrained('produit_variantes')->nullOnDelete();
            $table->integer('qte_recue');
            $table->decimal('cout_unitaire', 12, 2)->default(0);
            $table->foreignUlid('mouvement_stock_id')->nullable()->constrained('mouvements_stock')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_achat_lignes');
        Schema::dropIfExists('receptions_achats');
    }
};
