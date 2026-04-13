<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->dropColumn('unlock_at');
        });
    }

    public function down(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->date('unlock_at')->nullable()->after('earned_at');
        });
    }
};
