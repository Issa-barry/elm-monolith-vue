<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commande_vente_activites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['commande_vente_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_vente_activites');
    }
};
