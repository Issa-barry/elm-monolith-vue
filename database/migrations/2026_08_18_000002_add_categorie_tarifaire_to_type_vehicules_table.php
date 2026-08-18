<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classification commerciale (pas physique) du type de véhicule — détermine quel tarif usine
 * s'applique (cf. App\Enums\CategorieTarifaireVehicule, PrixUsineResolver). Nullable/non
 * contraint : un type sans valeur est traité comme "autres véhicules" par le resolver, jamais
 * bloquant pour les organisations existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->string('categorie_tarifaire', 30)->nullable()->after('nom');
        });
    }

    public function down(): void
    {
        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->dropColumn('categorie_tarifaire');
        });
    }
};
