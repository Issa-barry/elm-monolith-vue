<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_soldes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('cumul_achats')->default(0);
            $table->unsignedBigInteger('cashback_en_attente')->default(0);
            $table->unsignedBigInteger('total_cashback_gagne')->default(0);
            $table->unsignedBigInteger('total_cashback_verse')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_soldes');
    }
};
