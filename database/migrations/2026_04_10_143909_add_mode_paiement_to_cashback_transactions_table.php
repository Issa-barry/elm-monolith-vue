<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->string('mode_paiement')->nullable()->after('verse_le');
            $table->date('date_versement')->nullable()->after('mode_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->dropColumn(['mode_paiement', 'date_versement']);
        });
    }
};
