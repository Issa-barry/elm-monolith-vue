<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proprietaires', function (Blueprint $table) {
            $table->string('type', 20)->default('personne_physique')->after('personne_id');
            $table->string('raison_sociale')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('proprietaires', function (Blueprint $table) {
            $table->dropColumn(['type', 'raison_sociale']);
        });
    }
};
