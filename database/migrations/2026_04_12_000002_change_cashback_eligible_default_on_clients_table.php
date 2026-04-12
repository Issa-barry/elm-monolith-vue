<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('cashback_eligible')->default(false)->change();
        });

        // Met à jour les enregistrements existants qui avaient le défaut true
        DB::table('clients')->where('cashback_eligible', true)->update(['cashback_eligible' => false]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('cashback_eligible')->default(true)->change();
        });
    }
};
