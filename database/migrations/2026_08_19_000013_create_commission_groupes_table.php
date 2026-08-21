<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_groupes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('ancrage_type', 20);
            // Polymorphe léger (vehicules / sites / organizations selon ancrage_type).
            $table->ulid('ancrage_id');
            $table->string('statut', 20)->default('actif');
            $table->timestamps();

            $table->unique(['organization_id', 'type', 'ancrage_type', 'ancrage_id'], 'comm_groupes_ancrage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_groupes');
    }
};
