<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('montant');
            $table->unsignedBigInteger('montant_verse')->default(0);
            $table->string('statut', 20)->default('en_attente');
            $table->foreignUlid('vente_id')->nullable()->constrained('commandes_ventes')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('verse_le')->nullable();
            $table->timestamp('valide_le')->nullable();
            $table->foreignUlid('verse_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'statut']);
            $table->index(['client_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_transactions');
    }
};
