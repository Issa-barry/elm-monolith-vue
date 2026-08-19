<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_groupe_membres', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('groupe_id')->constrained('commission_groupes')->cascadeOnDelete();
            $table->string('beneficiaire_type', 20);
            // Polymorphe léger (livreurs / employes selon beneficiaire_type).
            $table->ulid('beneficiaire_id');
            $table->decimal('part_pourcentage', 5, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['groupe_id', 'effective_from', 'effective_to'], 'comm_groupe_membres_periode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_groupe_membres');
    }
};
