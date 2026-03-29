<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packing_id')->constrained('packings')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('montant');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['packing_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements');
    }
};
