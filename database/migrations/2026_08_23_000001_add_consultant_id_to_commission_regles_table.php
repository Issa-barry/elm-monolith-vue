<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_regles', function (Blueprint $table) {
            $table->foreignUlid('consultant_id')
                ->nullable()
                ->after('montant')
                ->constrained('prestataires')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_regles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultant_id');
        });
    }
};
