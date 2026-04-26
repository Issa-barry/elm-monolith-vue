<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements_commissions_ventes_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('paiement_id')->constrained('paiements_commissions_ventes')->cascadeOnDelete();
            $table->foreignUlid('commission_part_id')->constrained('commission_parts')->cascadeOnDelete();
            $table->decimal('amount_allocated', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_commissions_ventes_items');
    }
};
