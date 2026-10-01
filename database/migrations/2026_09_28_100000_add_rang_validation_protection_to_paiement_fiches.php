<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0010, lot 1 — socle « fiche = unité de validation et de paiement » :
 * - rang / fiche_origine_id : fiche complémentaire d'un même bénéficiaire dans la même
 *   période (la fiche déjà payée n'est jamais modifiée) ;
 * - report_a_deduire : déduction reportée sur la prochaine fiche du bénéficiaire ;
 * - validated_at / validated_by : validation au niveau de la fiche (utilisée au lot 2) ;
 * - paiement_fiche_paiements.fiche_id passe de cascade à restrict : une fiche ayant reçu un
 *   paiement ne peut plus être supprimée physiquement, quel que soit le code appelant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_fiches', function (Blueprint $table) {
            if (! Schema::hasColumn('paiement_fiches', 'rang')) {
                $table->unsignedSmallInteger('rang')->default(1)->after('beneficiaire_nom');
            }
            if (! Schema::hasColumn('paiement_fiches', 'fiche_origine_id')) {
                $table->foreignUlid('fiche_origine_id')->nullable()->after('rang')
                    ->constrained('paiement_fiches')->nullOnDelete();
            }
            if (! Schema::hasColumn('paiement_fiches', 'report_a_deduire')) {
                $table->decimal('report_a_deduire', 15, 2)->default(0)->after('montant_paye');
            }
            if (! Schema::hasColumn('paiement_fiches', 'validated_at')) {
                $table->timestamp('validated_at')->nullable()->after('statut');
            }
            if (! Schema::hasColumn('paiement_fiches', 'validated_by')) {
                $table->foreignUlid('validated_by')->nullable()->after('validated_at')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // Nouvel index d'abord : sous MySQL, l'ancien sert aussi la clé étrangère periode_id et
        // ne peut être supprimé qu'une fois un autre index commençant par periode_id en place.
        if (! Schema::hasIndex('paiement_fiches', 'pf_periode_ben_rang_unique')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->unique(['periode_id', 'beneficiaire_type', 'beneficiaire_id', 'rang'], 'pf_periode_ben_rang_unique');
            });
        }

        if (Schema::hasIndex('paiement_fiches', 'pf_periode_ben_unique')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->dropUnique('pf_periode_ben_unique');
            });
        }

        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            $table->dropForeign(['fiche_id']);
            $table->foreign('fiche_id')->references('id')->on('paiement_fiches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            $table->dropForeign(['fiche_id']);
            $table->foreign('fiche_id')->references('id')->on('paiement_fiches')->cascadeOnDelete();
        });

        // Ordre inverse de up() pour la même raison (index requis par la clé étrangère periode_id).
        // Échoue volontairement si des fiches complémentaires existent : le retour arrière
        // supprimerait une information de paiement.
        if (! Schema::hasIndex('paiement_fiches', 'pf_periode_ben_unique')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->unique(['periode_id', 'beneficiaire_type', 'beneficiaire_id'], 'pf_periode_ben_unique');
            });
        }

        if (Schema::hasIndex('paiement_fiches', 'pf_periode_ben_rang_unique')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->dropUnique('pf_periode_ben_rang_unique');
            });
        }

        Schema::table('paiement_fiches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validated_by');
            $table->dropColumn(['validated_at', 'report_a_deduire', 'rang']);
            $table->dropConstrainedForeignId('fiche_origine_id');
        });
    }
};
