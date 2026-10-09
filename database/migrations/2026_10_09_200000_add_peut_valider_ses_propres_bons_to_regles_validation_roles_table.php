<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validation de ses propres bons de commande : réglage par rôle (décision du 09/10/2026, révise
 * l'ADR 0021 point 4). Faux par défaut — la séparation créateur/modificateur ≠ validateur reste la
 * règle des rôles existants — sauf pour les règles super_admin déjà provisionnées, que le super
 * administrateur peut valider lui-même. Non destructive : aucune autre règle n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('regles_validation_roles', 'peut_valider_ses_propres_bons')) {
            Schema::table('regles_validation_roles', function (Blueprint $table) {
                $table->boolean('peut_valider_ses_propres_bons')->default(false)->after('plafond_illimite');
            });
        }

        DB::table('regles_validation_roles')
            ->where('domaine', 'achats')
            ->where('role_name', 'super_admin')
            ->update(['peut_valider_ses_propres_bons' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('regles_validation_roles', 'peut_valider_ses_propres_bons')) {
            Schema::table('regles_validation_roles', function (Blueprint $table) {
                $table->dropColumn('peut_valider_ses_propres_bons');
            });
        }
    }
};
