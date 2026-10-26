<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier salarié (lot 1 RH, docs/rh-employes-identite.md) — additive uniquement : toutes les
 * nouvelles colonnes sont facultatives, les 39 dossiers existants restent valides sans saisie.
 *
 * - personnes : données civiles partagées par tous les rôles de la personne.
 * - employes : données propres au dossier RH (embauche, sortie, service, CNSS, contact d'urgence).
 * - services_rh : référentiel par organisation, sur le modèle de fonctions_rh.
 * - employe_coordonnees_paiement : donnée sensible, table séparée, numéro chiffré par le modèle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services_rh', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 100);
            $table->string('code', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'libelle']);
        });

        Schema::table('personnes', function (Blueprint $table) {
            $table->date('date_naissance')->nullable()->after('email');
            $table->string('lieu_naissance', 100)->nullable()->after('date_naissance');
            $table->string('sexe', 1)->nullable()->after('lieu_naissance');
            $table->string('nationalite', 60)->nullable()->after('sexe');
        });

        Schema::table('employes', function (Blueprint $table) {
            $table->date('date_embauche')->nullable()->after('statut');
            $table->date('date_sortie')->nullable()->after('date_embauche');
            $table->string('motif_sortie', 255)->nullable()->after('date_sortie');
            // nullOnDelete : un service n'est jamais supprimé en usage normal (seulement désactivé).
            $table->foreignUlid('service_rh_id')->nullable()->after('fonction_rh_id')
                ->constrained('services_rh')->nullOnDelete();
            $table->string('numero_cnss', 30)->nullable()->after('motif_sortie');
            $table->string('contact_urgence_nom', 150)->nullable()->after('numero_cnss');
            $table->string('contact_urgence_lien', 50)->nullable()->after('contact_urgence_nom');
            $table->string('contact_urgence_telephone', 30)->nullable()->after('contact_urgence_lien');
        });

        Schema::create('employe_coordonnees_paiement', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employe_id')->unique()->constrained('employes')->cascadeOnDelete();
            $table->string('mode', 20);
            $table->string('etablissement', 100)->nullable();
            // Chiffré par le cast `encrypted` du modèle : jamais lisible en clair dans la base.
            $table->text('numero_compte')->nullable();
            $table->string('titulaire', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employe_coordonnees_paiement');

        Schema::table('employes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_rh_id');
            $table->dropColumn([
                'date_embauche', 'date_sortie', 'motif_sortie', 'numero_cnss',
                'contact_urgence_nom', 'contact_urgence_lien', 'contact_urgence_telephone',
            ]);
        });

        Schema::table('personnes', function (Blueprint $table) {
            $table->dropColumn(['date_naissance', 'lieu_naissance', 'sexe', 'nationalite']);
        });

        Schema::dropIfExists('services_rh');
    }
};
