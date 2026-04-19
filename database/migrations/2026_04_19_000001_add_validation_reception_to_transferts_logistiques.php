<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferts_logistiques', function (Blueprint $table) {
            $table->string('validation_reception')->nullable()->after('notes');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete()->after('validation_reception');
            $table->timestamp('validated_at')->nullable()->after('validated_by');
            $table->text('validation_motif')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('transferts_logistiques', function (Blueprint $table) {
            $table->dropForeign(['validated_by']);
            $table->dropColumn(['validation_reception', 'validated_by', 'validated_at', 'validation_motif']);
        });
    }
};
