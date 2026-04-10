<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // 'gain' = cashback déclenché par un achat | 'versement' = argent remis au client
            $table->enum('type', ['gain', 'versement']);

            $table->unsignedBigInteger('montant');

            // 'en_attente' = gain créé, pas encore versé | 'verse' = argent remis
            $table->enum('statut', ['en_attente', 'verse'])->default('en_attente');

            // Vente qui a déclenché le gain (null pour les versements)
            $table->foreignId('vente_id')->nullable()->constrained('commandes_ventes')->nullOnDelete();

            $table->text('note')->nullable();

            // Informations de versement
            $table->timestamp('verse_le')->nullable();
            $table->foreignId('verse_par')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Index de performance
            $table->index(['organization_id', 'statut']);
            $table->index(['client_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_transactions');
    }
};
