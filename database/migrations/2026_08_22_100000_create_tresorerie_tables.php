<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socle du chantier "Financement des agences" : supports de trésorerie
 * (caisse/banque/mobile money rattachés à un site + un compte du plan
 * comptable), soldes d'ouverture, et mouvements de fonds internes
 * (agence <-> siège). Chaque mouvement génère 2 pièces comptables mono-site
 * (émission puis réception, cf. MouvementFondsComptabilisationService) —
 * jamais de site_id par ligne dans compta_ecritures, EcritureComptableService
 * reste inchangé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Au plus un site principal de type "siege" par organisation — contrainte
            // applicative (SiegeResolverService), pas une contrainte SQL partielle
            // (portabilité SGBD) : cf. décision du chantier trésorerie.
            $table->boolean('is_siege_principal')->default(false)->after('type');
        });

        Schema::create('compta_supports_tresorerie', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('compte_comptable_id')->constrained('compta_comptes')->restrictOnDelete();
            $table->string('type', 20); // caisse | banque | mobile_money — cf. TypeSupportTresorerie
            $table->string('libelle', 150);
            // Étiquette libre "especes" | "mobile_money:orange" | "virement" ... utilisée
            // pour pré-sélectionner ce support dans un formulaire de paiement/mouvement —
            // jamais un opérateur codé en dur côté moteur (même convention que
            // compta_mappings.moyen_paiement).
            $table->string('moyen_paiement_defaut', 30)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'site_id']);
        });

        Schema::create('compta_soldes_ouverture', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Au plus un solde d'ouverture par support — un point de départ unique,
            // jamais rejouable (cf. décision du chantier : le système n'étant pas en
            // production, un seul relevé initial suffit, toute correction ultérieure
            // passe par un mouvement de fonds ou une contrepassation ordinaire).
            $table->foreignUlid('compte_tresorerie_id')->unique()->constrained('compta_supports_tresorerie')->cascadeOnDelete();
            $table->date('date_situation');
            $table->decimal('montant', 15, 2);
            $table->string('justificatif_path')->nullable();
            $table->text('commentaire')->nullable();
            $table->string('statut', 20)->default('brouillon'); // brouillon | valide
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('valide_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_at')->nullable();
            $table->foreignUlid('piece_comptable_id')->nullable()->constrained('compta_pieces')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mouvements_fonds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->foreignUlid('site_origine_id')->constrained('sites')->restrictOnDelete();
            $table->foreignUlid('site_destination_id')->constrained('sites')->restrictOnDelete();
            $table->foreignUlid('compte_tresorerie_origine_id')->constrained('compta_supports_tresorerie')->restrictOnDelete();
            $table->foreignUlid('compte_tresorerie_destination_id')->constrained('compta_supports_tresorerie')->restrictOnDelete();
            $table->decimal('montant', 15, 2);
            $table->string('moyen_transfert', 30)->nullable();
            $table->string('reference_externe', 100)->nullable();
            $table->date('date_envoi')->nullable();
            $table->date('date_reception')->nullable();
            $table->string('justificatif_path')->nullable();
            $table->text('commentaire')->nullable();
            $table->string('statut', 20)->default('brouillon'); // cf. StatutMouvementFonds
            $table->text('motif_annulation')->nullable();

            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            // Une pièce par jambe (émission / réception) — jamais la même pièce pour les
            // deux sites, cf. docblock de fichier.
            $table->foreignUlid('piece_comptable_envoi_id')->nullable()->constrained('compta_pieces')->nullOnDelete();
            $table->foreignUlid('piece_comptable_reception_id')->nullable()->constrained('compta_pieces')->nullOnDelete();

            $table->timestamps();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'site_origine_id']);
            $table->index(['organization_id', 'site_destination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_fonds');
        Schema::dropIfExists('compta_soldes_ouverture');
        Schema::dropIfExists('compta_supports_tresorerie');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('is_siege_principal');
        });
    }
};
