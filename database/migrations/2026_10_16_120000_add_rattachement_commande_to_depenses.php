<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement d'une dépense de carburant à la commande qui a fait rouler le véhicule (ADR 0031,
 * cf. docs/depenses-carburant-commande.md).
 *
 * - `depense_types.commande_obligatoire` : le type exige une commande de vente ou un transfert
 *   logistique (ou une exception motivée). Réglable par organisation dans Dépenses → Types.
 * - `depenses.commande_vente_id` / `depenses.transfert_logistique_id` : la commande de référence,
 *   au plus une des deux. `depenses.motif_sans_commande` : l'exception motivée.
 *
 * Données : les dépenses existantes ne sont pas touchées (colonnes nulles). Les types carburant
 * existants (catégorie véhicule, code généré depuis un libellé « Carburant… ») reçoivent
 * `commande_obligatoire = true` : c'est la règle demandée, et elle reste modifiable par type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('depense_types', 'commande_obligatoire')) {
            Schema::table('depense_types', function (Blueprint $table) {
                $table->boolean('commande_obligatoire')->default(false)->after('justificatif_obligatoire');
            });
        }

        if (! Schema::hasColumn('depenses', 'commande_vente_id')) {
            Schema::table('depenses', function (Blueprint $table) {
                $table->foreignUlid('commande_vente_id')->nullable()->after('beneficiaire_id')
                    ->constrained('commandes_ventes')->nullOnDelete();
                $table->foreignUlid('transfert_logistique_id')->nullable()->after('commande_vente_id')
                    ->constrained('transferts_logistiques')->nullOnDelete();
                $table->text('motif_sans_commande')->nullable()->after('transfert_logistique_id');
            });
        }

        DB::table('depense_types')
            ->where('categorie', 'vehicule')
            ->where('code', 'like', 'carburant%')
            ->update(['commande_obligatoire' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('depenses', 'commande_vente_id')) {
            Schema::table('depenses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('commande_vente_id');
                $table->dropConstrainedForeignId('transfert_logistique_id');
                $table->dropColumn('motif_sans_commande');
            });
        }

        if (Schema::hasColumn('depense_types', 'commande_obligatoire')) {
            Schema::table('depense_types', function (Blueprint $table) {
                $table->dropColumn('commande_obligatoire');
            });
        }
    }
};
