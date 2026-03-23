<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->decimal('taux_commission_proprietaire', 5, 2)->default(0)->after('taux_commission_livreur');
        });

        Schema::table('commissions_ventes', function (Blueprint $table) {
            $table->decimal('taux_commission_proprietaire', 5, 2)->default(0)->after('taux_commission');
            $table->decimal('montant_part_livreur', 15, 2)->default(0)->after('montant_commission');
            $table->decimal('montant_part_proprietaire', 15, 2)->default(0)->after('montant_part_livreur');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('taux_commission_proprietaire');
        });

        Schema::table('commissions_ventes', function (Blueprint $table) {
            $table->dropColumn(['taux_commission_proprietaire', 'montant_part_livreur', 'montant_part_proprietaire']);
        });
    }
};
