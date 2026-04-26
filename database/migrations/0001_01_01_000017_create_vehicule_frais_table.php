<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicule_frais', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicule_id')->constrained('vehicules')->cascadeOnDelete();
            $table->decimal('montant', 10, 2);
            $table->string('type', 30);
            $table->string('commentaire', 150)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicule_frais');
    }
};
