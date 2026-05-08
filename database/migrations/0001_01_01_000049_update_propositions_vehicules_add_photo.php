<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('propositions_vehicules', function (Blueprint $table) {
            $table->string('nom_vehicule', 100)->nullable()->change();
            $table->string('photo_path', 255)->nullable()->after('commentaire');
        });
    }

    public function down(): void
    {
        Schema::table('propositions_vehicules', function (Blueprint $table) {
            $table->string('nom_vehicule', 100)->nullable(false)->change();
            $table->dropColumn('photo_path');
        });
    }
};
