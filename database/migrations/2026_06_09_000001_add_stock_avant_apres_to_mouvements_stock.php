<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->unsignedInteger('stock_avant')->nullable()->after('quantite');
            $table->unsignedInteger('stock_apres')->nullable()->after('stock_avant');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropColumn(['stock_avant', 'stock_apres']);
        });
    }
};
