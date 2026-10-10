<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validation de ses propres factures d'achat : réglage par rôle (décision du 10/10/2026, même
 * principe que les bons de commande, cf. 2026_10_09_200000). Faux par défaut — la séparation
 * saisie/validation reste la règle des rôles existants — sauf pour les règles super_admin, que le
 * super administrateur peut valider lui-même. Non destructive : aucune autre règle n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('regles_validation_roles', 'peut_valider_ses_propres_factures')) {
            Schema::table('regles_validation_roles', function (Blueprint $table) {
                $table->boolean('peut_valider_ses_propres_factures')->default(false)->after('peut_valider_ses_propres_bons');
            });
        }

        DB::table('regles_validation_roles')
            ->where('domaine', 'achats')
            ->where('role_name', 'super_admin')
            ->update(['peut_valider_ses_propres_factures' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('regles_validation_roles', 'peut_valider_ses_propres_factures')) {
            Schema::table('regles_validation_roles', function (Blueprint $table) {
                $table->dropColumn('peut_valider_ses_propres_factures');
            });
        }
    }
};
