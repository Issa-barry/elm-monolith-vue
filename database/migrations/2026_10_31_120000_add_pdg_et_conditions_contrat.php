<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrat de travail généré automatiquement (ADR 0048) :
 * - `organizations.pdg_employe_id` : le PDG, employé RH désigné, signataire de tous les contrats.
 *   Une seule colonne = un seul PDG par organisation ; l'index unique empêche un même employé
 *   d'être PDG de deux organisations.
 * - `contrats` : période d'essai (nombre + unité), heures de travail par semaine et motif du CDD,
 *   saisis à la création du contrat au lieu d'un texte libre au moment de la génération.
 * Colonnes nouvelles et vides : aucune donnée existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'pdg_employe_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->foreignUlid('pdg_employe_id')->nullable()->unique()
                    ->constrained('employes')->nullOnDelete();
            });
        }

        Schema::table('contrats', function (Blueprint $table) {
            if (! Schema::hasColumn('contrats', 'periode_essai_duree')) {
                $table->unsignedSmallInteger('periode_essai_duree')->nullable()->after('salaire_base');
            }
            if (! Schema::hasColumn('contrats', 'periode_essai_unite')) {
                $table->string('periode_essai_unite', 10)->nullable()->after('periode_essai_duree');
            }
            if (! Schema::hasColumn('contrats', 'heures_hebdomadaires')) {
                $table->decimal('heures_hebdomadaires', 5, 2)->nullable()->after('periode_essai_unite');
            }
            if (! Schema::hasColumn('contrats', 'motif_cdd')) {
                $table->string('motif_cdd', 255)->nullable()->after('heures_hebdomadaires');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            $table->dropColumn(['periode_essai_duree', 'periode_essai_unite', 'heures_hebdomadaires', 'motif_cdd']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pdg_employe_id');
        });
    }
};
