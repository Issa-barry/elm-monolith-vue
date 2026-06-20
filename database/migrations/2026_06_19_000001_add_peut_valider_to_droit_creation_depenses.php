<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('droit_creation_depenses', function (Blueprint $table) {
            $table->boolean('peut_valider')->default(false)->after('is_actif');
        });
    }

    public function down(): void
    {
        Schema::table('droit_creation_depenses', function (Blueprint $table) {
            $table->dropColumn('peut_valider');
        });
    }
};
