<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allocation FIFO : lie chaque paiement aux parts de commission couvertes.
 * Une même part peut être couverte par plusieurs paiements (versement partiel).
 * Un même paiement peut couvrir plusieurs parts (paiement groupé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payment_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('payment_id');
            $table->foreign('payment_id', 'cpi_payment_id_fk')
                ->references('id')
                ->on('commission_payments')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('part_id');
            $table->foreign('part_id', 'cpi_part_id_fk')
                ->references('id')
                ->on('commission_logistique_parts')
                ->cascadeOnDelete();

            $table->decimal('amount_allocated', 12, 2);
            $table->timestamps();

            // Un paiement donné ne peut allouer qu'une fois sur une même part
            $table->unique(['payment_id', 'part_id'], 'cpi_payment_part_unique');
            $table->index('part_id', 'cpi_part_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payment_items');
    }
};
