<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements_commissions_ventes_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paiement_id')
                ->constrained('paiements_commissions_ventes')
                ->cascadeOnDelete();
            $table->foreignId('commission_part_id')
                ->constrained('commission_parts')
                ->cascadeOnDelete();
            $table->decimal('amount_allocated', 15, 2);
            $table->timestamps();

            $table->index('commission_part_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_commissions_ventes_items');
    }
};
