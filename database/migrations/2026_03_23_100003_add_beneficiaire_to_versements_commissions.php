<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versements_commissions', function (Blueprint $table) {
            $table->string('beneficiaire')->default('livreur')->after('montant'); // livreur | proprietaire
        });

        Schema::table('commissions_ventes', function (Blueprint $table) {
            $table->decimal('montant_verse_livreur', 15, 2)->default(0)->after('montant_verse');
            $table->decimal('montant_verse_proprietaire', 15, 2)->default(0)->after('montant_verse_livreur');
        });
    }

    public function down(): void
    {
        Schema::table('versements_commissions', function (Blueprint $table) {
            $table->dropColumn('beneficiaire');
        });

        Schema::table('commissions_ventes', function (Blueprint $table) {
            $table->dropColumn(['montant_verse_livreur', 'montant_verse_proprietaire']);
        });
    }
};
