<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_parts', function (Blueprint $table) {
            $table->string('type_frais', 20)->nullable()->after('frais_supplementaires');      // carburant|reparation|autre
            $table->string('commentaire_frais', 150)->nullable()->after('type_frais');          // obligatoire si type = autre
        });
    }

    public function down(): void
    {
        Schema::table('commission_parts', function (Blueprint $table) {
            $table->dropColumn(['type_frais', 'commentaire_frais']);
        });
    }
};
