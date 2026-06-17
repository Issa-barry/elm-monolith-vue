<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_fiches', function (Blueprint $table) {
            $table->string('reference', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('paiement_fiches', function (Blueprint $table) {
            $table->string('reference', 20)->change();
        });
    }
};
