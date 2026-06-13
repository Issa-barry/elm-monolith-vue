<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->string('type_vehicule', 30)->nullable()->change();
            $table->string('type_vehicule_id')->nullable()->after('type_vehicule');
            $table->foreign('type_vehicule_id')->references('id')->on('type_vehicules')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropForeign(['type_vehicule_id']);
            $table->dropColumn('type_vehicule_id');
            $table->string('type_vehicule', 30)->nullable(false)->change();
        });
    }
};
