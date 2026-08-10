<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacité par catégorie de produit, par défaut pour un type de véhicule — repris par tout
 * véhicule de ce type qui n'a pas sa propre ligne dans vehicule_capacites pour cette catégorie
 * (même repli que capacite_packs ?? type_vehicules.capacite_defaut aujourd'hui).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_vehicule_capacites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('type_vehicule_id')->constrained('type_vehicules')->cascadeOnDelete();
            $table->foreignUlid('categorie_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('capacite_max');
            $table->timestamps();

            $table->unique(['type_vehicule_id', 'categorie_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_vehicule_capacites');
    }
};
