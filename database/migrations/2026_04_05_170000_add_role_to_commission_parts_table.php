<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_parts', function (Blueprint $table) {
            $table->string('role', 50)->nullable()->after('beneficiaire_nom');
        });
    }

    public function down(): void
    {
        Schema::table('commission_parts', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
