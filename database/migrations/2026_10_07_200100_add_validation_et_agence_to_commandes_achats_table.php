<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bon de commande fournisseur refondu (ADR 0021) :
 * - agence bénéficiaire (le stock réceptionné entre sur ce site) ;
 * - dernier auteur d'une modification du CONTENU (`updated_by` bouge à chaque transition, y
 *   compris la validation, et ne peut donc pas servir à la règle « modificateur ≠ validateur ») ;
 * - validation : auteur, date, montant et snapshot (fournisseur, agence, règle de plafond) ;
 * - clôture du reliquat.
 * Côté lignes : référence (SKU) de la variante, figée comme son libellé.
 *
 * Toutes les colonnes sont nullable : les commandes historiques `ACH-…` n'ont ni agence ni
 * validation, et gardent leurs valeurs actuelles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_achats', function (Blueprint $table) {
            if (! Schema::hasColumn('commandes_achats', 'site_id')) {
                $table->foreignUlid('site_id')->nullable()->after('fournisseur_id')->constrained('sites')->nullOnDelete();
            }
            if (! Schema::hasColumn('commandes_achats', 'numero')) {
                $table->unsignedInteger('numero')->nullable()->after('reference');
            }
            if (! Schema::hasColumn('commandes_achats', 'contenu_modifie_par')) {
                $table->foreignUlid('contenu_modifie_par')->nullable()->after('statut')->constrained('users')->nullOnDelete();
                $table->timestamp('contenu_modifie_at')->nullable()->after('contenu_modifie_par');
            }
            if (! Schema::hasColumn('commandes_achats', 'validee_at')) {
                $table->timestamp('validee_at')->nullable()->after('contenu_modifie_at');
                $table->foreignUlid('validee_par')->nullable()->after('validee_at')->constrained('users')->nullOnDelete();
                $table->decimal('montant_valide', 12, 2)->nullable()->after('validee_par');
                $table->string('fournisseur_nom_snapshot')->nullable()->after('montant_valide');
                $table->string('site_nom_snapshot')->nullable()->after('fournisseur_nom_snapshot');
                $table->json('validation_regle_snapshot')->nullable()->after('site_nom_snapshot');
            }
            if (! Schema::hasColumn('commandes_achats', 'cloturee_at')) {
                $table->timestamp('cloturee_at')->nullable()->after('annulee_par');
                $table->foreignUlid('cloturee_par')->nullable()->after('cloturee_at')->constrained('users')->nullOnDelete();
                $table->text('motif_cloture')->nullable()->after('cloturee_par');
            }
        });

        Schema::table('commande_achat_lignes', function (Blueprint $table) {
            if (! Schema::hasColumn('commande_achat_lignes', 'reference_snapshot')) {
                $table->string('reference_snapshot')->nullable()->after('libelle_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('commande_achat_lignes', function (Blueprint $table) {
            $table->dropColumn('reference_snapshot');
        });

        Schema::table('commandes_achats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('contenu_modifie_par');
            $table->dropConstrainedForeignId('validee_par');
            $table->dropConstrainedForeignId('cloturee_par');
            $table->dropColumn([
                'numero', 'contenu_modifie_at', 'validee_at', 'montant_valide', 'fournisseur_nom_snapshot',
                'site_nom_snapshot', 'validation_regle_snapshot', 'cloturee_at', 'motif_cloture',
            ]);
        });
    }
};
