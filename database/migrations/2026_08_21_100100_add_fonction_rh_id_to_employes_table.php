<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive : `fonction_rh_id` nullable — un employé existant sans fonction assignée reste valide
 * (jamais de migration destructive). `nullOnDelete` : une fonction désactivée (jamais supprimée en
 * usage normal, cf. FonctionRhController) ne doit théoriquement jamais déclencher ce cas, mais reste
 * un filet de sécurité si une fonction est malgré tout supprimée en base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->foreignUlid('fonction_rh_id')->nullable()->after('type_employe')
                ->constrained('fonctions_rh')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fonction_rh_id');
        });
    }
};
