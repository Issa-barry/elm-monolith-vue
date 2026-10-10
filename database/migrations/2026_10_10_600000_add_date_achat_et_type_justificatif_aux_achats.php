<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Achats sans facture ou sans numéro, et date réelle de l'achat (décision du 10/10/2026) :
 * - `commandes_achats.date_achat` : date métier de l'achat, distincte de la date de saisie ; les
 *   bons existants reçoivent leur date de saisie ;
 * - `factures_fournisseurs.type_justificatif` : facture (défaut, donc inchangé pour l'existant),
 *   reçu, ticket ou aucun document ;
 * - `factures_fournisseurs.numero_facture_fournisseur` devient facultatif. Le contrôle de doublon
 *   repose sur `cle_numero_unique`, déjà nullable : il reste actif dès qu'un numéro est saisi.
 *
 * Non destructive : aucune donnée existante n'est modifiée en dehors de l'initialisation ci-dessus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commandes_achats', 'date_achat')) {
            Schema::table('commandes_achats', function (Blueprint $table) {
                $table->date('date_achat')->nullable()->after('reference');
            });
        }
        DB::table('commandes_achats')->whereNull('date_achat')->update(['date_achat' => DB::raw('DATE(created_at)')]);

        if (! Schema::hasColumn('factures_fournisseurs', 'type_justificatif')) {
            Schema::table('factures_fournisseurs', function (Blueprint $table) {
                $table->string('type_justificatif', 20)->default('facture')->after('numero_facture_fournisseur');
            });
        }

        Schema::table('factures_fournisseurs', function (Blueprint $table) {
            $table->string('numero_facture_fournisseur', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('factures_fournisseurs', 'type_justificatif')) {
            Schema::table('factures_fournisseurs', function (Blueprint $table) {
                $table->dropColumn('type_justificatif');
            });
        }
        if (Schema::hasColumn('commandes_achats', 'date_achat')) {
            Schema::table('commandes_achats', function (Blueprint $table) {
                $table->dropColumn('date_achat');
            });
        }
    }
};
