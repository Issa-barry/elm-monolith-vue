<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deuxième unité de capacité, en miroir de capacite_packs/capacite_defaut
 * (sachets) — un même véhicule peut transporter des sachets ET des bouteilles,
 * avec un plafond différent pour chacun. Nullable des deux côtés : contrairement
 * à capacite_defaut (sachets, obligatoire), la plupart des types/véhicules ne
 * transportent pas de bouteilles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->unsignedInteger('capacite_defaut_bouteilles')->nullable()->after('capacite_defaut');
        });

        Schema::table('vehicules', function (Blueprint $table) {
            $table->unsignedInteger('capacite_bouteilles')->nullable()->after('capacite_packs');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('capacite_bouteilles');
        });

        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->dropColumn('capacite_defaut_bouteilles');
        });
    }
};
