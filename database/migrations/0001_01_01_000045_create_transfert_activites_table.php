<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfert_activites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfert_logistique_id')->constrained('transferts_logistiques')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['transfert_logistique_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfert_activites');
    }
};
