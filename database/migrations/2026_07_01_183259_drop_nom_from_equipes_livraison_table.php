<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            if (Schema::hasColumn('equipes_livraison', 'nom')) {
                $table->dropColumn('nom');
            }
        });
    }

    public function down(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->string('nom')->after('organization_id');
        });
    }
};
