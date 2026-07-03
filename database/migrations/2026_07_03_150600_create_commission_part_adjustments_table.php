<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_part_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('commission_part_type');
            $table->ulid('commission_part_id');
            $table->decimal('ancien_montant', 15, 2);
            $table->decimal('nouveau_montant', 15, 2);
            $table->string('motif', 30);
            $table->string('commentaire', 500)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['commission_part_type', 'commission_part_id'], 'comm_part_adjustments_part_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_part_adjustments');
    }
};
