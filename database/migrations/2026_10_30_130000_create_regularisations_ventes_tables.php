<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Régularisation d'une vente après encaissement (ADR 0043) :
 *  - `commande_vente_retours.nature` distingue le mouvement de quantité d'une régularisation
 *    (`regularisation`) d'un retour de livraison (`retour_livraison`, toutes les lignes existantes) ;
 *  - `regularisations_ventes` : trace de l'opération (auteur, motif, confirmation, avant/après) ;
 *  - `commission_ecarts_regularisation` : écart entre une commission déjà payée (ou figée sur une
 *    fiche payée) et son montant recalculé, suivi jusqu'à sa régularisation financière.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commande_vente_retours', 'nature')) {
            Schema::table('commande_vente_retours', function (Blueprint $table) {
                $table->string('nature', 30)->default('retour_livraison')->after('commande_vente_id');
            });
        }

        if (! Schema::hasTable('regularisations_ventes')) {
            Schema::create('regularisations_ventes', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
                $table->foreignUlid('commande_vente_retour_id')->nullable()->constrained('commande_vente_retours')->nullOnDelete();
                $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('motif');
                $table->string('traitement_excedent', 20)->nullable();
                $table->decimal('montant_avant', 14, 2);
                $table->decimal('montant_apres', 14, 2);
                $table->decimal('encaisse_avant', 14, 2);
                $table->decimal('excedent', 14, 2)->default(0);
                $table->decimal('commissions_avant', 14, 2)->default(0);
                $table->decimal('commissions_apres', 14, 2)->default(0);
                $table->string('empreinte', 64);
                $table->string('methode_confirmation', 20);
                $table->string('code_envoye_a')->nullable();
                $table->timestamp('code_demande_at')->nullable();
                $table->json('recapitulatif');
                $table->json('operations');
                $table->timestamp('confirmee_at');
                $table->timestamps();

                $table->index(['commande_vente_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('commission_ecarts_regularisation')) {
            Schema::create('commission_ecarts_regularisation', function (Blueprint $table) {
                $table->ulid('id')->primary();
                // Noms de contrainte explicites : les noms générés dépassent 64 caractères (MySQL).
                $table->foreignUlid('organization_id')->constrained(indexName: 'ecarts_regul_org_fk')->cascadeOnDelete();
                $table->foreignUlid('regularisation_vente_id')->constrained('regularisations_ventes', indexName: 'ecarts_regul_regularisation_fk')->cascadeOnDelete();
                $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes', indexName: 'ecarts_regul_commande_fk')->cascadeOnDelete();
                $table->foreignUlid('commission_enveloppe_part_id')->constrained('commission_enveloppe_parts', indexName: 'ecarts_regul_part_fk')->restrictOnDelete();
                $table->string('beneficiaire_type', 30);
                $table->string('beneficiaire_id', 26);
                $table->string('beneficiaire_libelle')->nullable();
                $table->decimal('montant_avant', 14, 2);
                $table->decimal('montant_apres', 14, 2);
                $table->decimal('montant_verse', 14, 2);
                $table->decimal('ecart', 14, 2);
                $table->string('sens', 20);
                $table->string('statut', 20)->default('a_regulariser');
                $table->text('commentaire_regularisation')->nullable();
                $table->foreignUlid('regularise_par')->nullable()->constrained('users', indexName: 'ecarts_regul_user_fk')->nullOnDelete();
                $table->timestamp('regularise_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'statut'], 'ecarts_regul_org_statut_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ecarts_regularisation');
        Schema::dropIfExists('regularisations_ventes');

        if (Schema::hasColumn('commande_vente_retours', 'nature')) {
            Schema::table('commande_vente_retours', function (Blueprint $table) {
                $table->dropColumn('nature');
            });
        }
    }
};
