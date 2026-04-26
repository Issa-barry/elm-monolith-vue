<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payment_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->constrained('commission_payments')->cascadeOnDelete();
            $table->foreignUlid('part_id')->constrained('commission_logistique_parts')->cascadeOnDelete();
            $table->decimal('amount_allocated', 12, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payment_items');
    }
};
