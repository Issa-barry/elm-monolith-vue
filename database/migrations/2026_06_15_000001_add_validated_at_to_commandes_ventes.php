<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->timestamp('validated_at')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropColumn('validated_at');
        });
    }
};
