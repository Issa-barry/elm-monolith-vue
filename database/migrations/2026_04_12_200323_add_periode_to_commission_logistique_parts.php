<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            // "2026-04-P1" | "2026-04-P2" | "2026-04-M"
            $table->string('periode', 12)->nullable()->after('unlock_at');
            $table->index('periode');
        });
    }

    public function down(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->dropIndex(['periode']);
            $table->dropColumn('periode');
        });
    }
};
