<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pays', 100)->nullable()->after('is_active');
            $table->char('code_pays', 2)->nullable()->after('pays');
            $table->string('ville', 100)->nullable()->after('code_pays');
            $table->string('adresse', 255)->nullable()->after('ville');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pays', 'code_pays', 'ville', 'adresse']);
        });
    }
};
