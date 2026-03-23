<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->string('ville', 100)->nullable()->after('adresse');
            $table->string('pays', 100)->nullable()->after('ville');
            $table->string('code_pays', 5)->nullable()->after('pays');
            $table->string('code_phone_pays', 10)->nullable()->after('code_pays');
        });
    }

    public function down(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->dropColumn(['ville', 'pays', 'code_pays', 'code_phone_pays']);
        });
    }
};
