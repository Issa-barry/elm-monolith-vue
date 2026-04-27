<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions_ventes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->decimal('montant_commande', 15, 2);
            $table->decimal('montant_commission_totale', 15, 2);
            $table->decimal('montant_verse', 15, 2)->default(0);
            $table->string('statut', 30)->default('en_attente');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_ventes');
    }
};
