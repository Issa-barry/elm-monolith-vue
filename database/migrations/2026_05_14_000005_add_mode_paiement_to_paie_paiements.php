<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paie_paiements', function (Blueprint $table) {
            $table->string('mode_paiement', 20)->default('especes')->after('date_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('paie_paiements', function (Blueprint $table) {
            $table->dropColumn('mode_paiement');
        });
    }
};
