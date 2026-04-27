<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements_commissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commission_part_id')->constrained('commission_parts')->cascadeOnDelete();
            $table->decimal('montant', 15, 2);
            $table->date('date_versement');
            $table->string('mode_paiement', 30)->default('especes');
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements_commissions');
    }
};
