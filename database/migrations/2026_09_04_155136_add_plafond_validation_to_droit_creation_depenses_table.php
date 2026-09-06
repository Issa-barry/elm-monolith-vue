<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plafond de validation par rôle (GNF, en unités entières comme `montant` sur
 * `depenses`). NULL = aucun plafond configuré, ce qui est traité comme
 * "0 GNF" (deny-by-default, cf. peutValiderMontant()) tant que peut_valider
 * est actif — jamais interprété comme "illimité" pour éviter qu'une ligne mal
 * configurée n'ouvre un accès sans limite. Super Admin et Admin Entreprise
 * restent en dehors de ce mécanisme (bypass isAdmin(), non concernés par
 * cette table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('droit_creation_depenses', function (Blueprint $table) {
            $table->decimal('plafond_validation', 12, 2)->nullable()->after('peut_valider');
        });
    }

    public function down(): void
    {
        Schema::table('droit_creation_depenses', function (Blueprint $table) {
            $table->dropColumn('plafond_validation');
        });
    }
};
