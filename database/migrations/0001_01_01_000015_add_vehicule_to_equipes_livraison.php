<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->foreignUlid('vehicule_id')->nullable()->after('proprietaire_id')->constrained('vehicules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->dropForeign(['vehicule_id']);
            $table->dropColumn('vehicule_id');
        });
    }
};
