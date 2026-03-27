<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('localisation');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('telephone', 50)->nullable()->after('longitude');
            $table->string('email', 255)->nullable()->after('telephone');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'telephone', 'email']);
        });
    }
};
