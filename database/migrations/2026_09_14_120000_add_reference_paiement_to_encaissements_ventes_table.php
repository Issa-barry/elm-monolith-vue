<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->string('reference_paiement', 190)->nullable()->after('mode_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->dropColumn('reference_paiement');
        });
    }
};
