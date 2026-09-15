<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->string('operateur_mobile_money', 30)->nullable()->after('mode_paiement');
            $table->string('reference_paiement', 190)->nullable()->after('operateur_mobile_money');
        });
    }

    public function down(): void
    {
        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->dropColumn(['operateur_mobile_money', 'reference_paiement']);
        });
    }
};
