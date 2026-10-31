<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catégorie dont les produits ne peuvent être vendus avec un véhicule que si ce véhicule a une
 * capacité maximale configurée pour elle (ADR 0042). Faux pour toutes les catégories existantes :
 * l'organisation active le réglage elle-même, catégorie par catégorie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'capacite_vehicule_obligatoire')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('capacite_vehicule_obligatoire')->default(false)->after('statut');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('categories', 'capacite_vehicule_obligatoire')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('capacite_vehicule_obligatoire');
        });
    }
};
