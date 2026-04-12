<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complète la table commission_logistique_parts :
 *  - colonnes manquantes (proprietaire_id, frais_*, montant_net)
 *  - unlock_at / earned_at pour la disponibilité différée
 *  - correction de la contrainte unique (couvre aussi les proprietaires)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            // Colonnes manquantes dans la migration initiale
            $table->foreignId('proprietaire_id')
                ->nullable()
                ->after('livreur_id')
                ->constrained('proprietaires')
                ->nullOnDelete();

            $table->decimal('frais_supplementaires', 12, 2)->default(0)->after('montant_brut');
            $table->string('type_frais')->nullable()->after('frais_supplementaires');
            $table->string('commentaire_frais')->nullable()->after('type_frais');
            $table->decimal('montant_net', 12, 2)->default(0)->after('commentaire_frais');

            // Disponibilité différée
            // earned_at : date à laquelle la commission est acquise (= date réception transfert)
            // unlock_at : date à laquelle le versement est autorisé
            //   livreur       : earned_at + 14 jours
            //   propriétaire  : 1er du mois suivant earned_at
            $table->date('earned_at')->nullable()->after('montant_verse');
            $table->date('unlock_at')->nullable()->after('earned_at');
        });

        // Supprimer l'ancienne contrainte unique incomplète (livreur_id NULL = contournement)
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->dropUnique('parts_commission_unique');
        });

        // Nouvelle contrainte unique : couvre les deux types de bénéficiaire
        // Coalesce simulé via index conditionnel n'étant pas portable,
        // on utilise deux index uniques partiels => non supporté en MySQL.
        // Solution pragmatique : unique sur (commission_id, type_beneficiaire, livreur_id, proprietaire_id)
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->unique(
                ['commission_logistique_id', 'type_beneficiaire', 'livreur_id', 'proprietaire_id'],
                'parts_commission_beneficiaire_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->dropUnique('parts_commission_beneficiaire_unique');
            $table->dropForeign(['proprietaire_id']);
            $table->dropColumn([
                'proprietaire_id',
                'frais_supplementaires',
                'type_frais',
                'commentaire_frais',
                'montant_net',
                'earned_at',
                'unlock_at',
            ]);
            $table->unique(
                ['commission_logistique_id', 'type_beneficiaire', 'livreur_id'],
                'parts_commission_unique'
            );
        });
    }
};
