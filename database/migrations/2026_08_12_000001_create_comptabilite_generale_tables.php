<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Socle de la comptabilité générale (SYSCOHADA) — vient en aval des modules
 * métier existants (commissions, dépenses, fiches de paiement), sans les
 * remplacer. Regroupé dans une seule migration (9 tables fortement liées,
 * livrées comme un socle atomique) plutôt qu'un fichier par table.
 *
 * Aucun numéro de compte SYSCOHADA n'est codé ici : les comptes/mappings sont
 * des données, pas du schéma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journaux_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10); // VE, AC, CA, BQ, MM, OD, ...
            $table->string('libelle', 100);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('comptes_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 20);
            $table->string('libelle', 150);
            $table->foreignUlid('parent_id')->nullable()->constrained('comptes_comptables')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'numero']);
        });

        Schema::create('exercices_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 50);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 20)->default('ouvert'); // ouvert | cloture
            $table->timestamp('cloture_at')->nullable();
            $table->foreignUlid('cloture_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'date_debut', 'date_fin'], 'exercices_org_range_unique');
        });

        Schema::create('periodes_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('exercice_comptable_id')->constrained('exercices_comptables')->cascadeOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 20)->default('ouverte'); // ouverte | cloturee
            $table->timestamp('cloture_at')->nullable();
            $table->foreignUlid('cloture_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'date_debut', 'date_fin'], 'periodes_org_range_unique');
            $table->index(['organization_id', 'statut']);
        });

        Schema::create('tiers_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // proprietaire | livreur | salarie | client | fournisseur
            $table->string('tiersable_type');
            $table->ulid('tiersable_id');
            $table->foreignUlid('compte_collectif_id')->constrained('comptes_comptables')->restrictOnDelete();
            $table->string('libelle', 150);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'tiersable_type', 'tiersable_id'], 'tiers_org_tiersable_unique');
            $table->index(['organization_id', 'type']);
        });

        Schema::create('compte_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('evenement', 60); // ex: fiche_proprietaire_validee
            $table->string('role', 40); // ex: charge_commission | dette_tiers | avance_tiers | tresorerie
            $table->string('moyen_paiement', 30)->nullable(); // caisse | banque | mobile_money:orange | ... — null = défaut de l'événement
            $table->foreignUlid('compte_comptable_id')->constrained('comptes_comptables')->restrictOnDelete();
            $table->foreignUlid('journal_comptable_id')->nullable()->constrained('journaux_comptables')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'evenement', 'role', 'moyen_paiement'], 'compte_mapping_unique');
        });

        Schema::create('pieces_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_comptable_id')->constrained('journaux_comptables')->restrictOnDelete();
            $table->foreignUlid('exercice_comptable_id')->constrained('exercices_comptables')->restrictOnDelete();
            $table->foreignUlid('periode_comptable_id')->constrained('periodes_comptables')->restrictOnDelete();
            $table->string('numero', 30);
            $table->date('date_piece');
            $table->string('libelle', 255);
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();
            $table->string('type_evenement', 100);
            $table->string('statut', 20)->default('validee'); // validee | contrepassee
            $table->foreignUlid('piece_origine_id')->nullable()->constrained('pieces_comptables')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'source_type', 'source_id', 'type_evenement'], 'pieces_idempotency_unique');
            $table->unique(['organization_id', 'journal_comptable_id', 'exercice_comptable_id', 'numero'], 'pieces_numero_unique');
            $table->index(['organization_id', 'periode_comptable_id']);
            $table->index(['organization_id', 'date_piece']);
        });

        Schema::create('ecritures_comptables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('piece_comptable_id')->constrained('pieces_comptables')->cascadeOnDelete();
            $table->foreignUlid('compte_comptable_id')->constrained('comptes_comptables')->restrictOnDelete();
            $table->foreignUlid('tiers_comptable_id')->nullable()->constrained('tiers_comptables')->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('libelle', 255);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['compte_comptable_id']);
            $table->index(['tiers_comptable_id']);
            $table->index(['piece_comptable_id']);
        });

        // Numérotation séquentielle sûre en concurrence (verrouillée en transaction),
        // volontairement séparée de pieces_comptables pour ne jamais dépendre d'un
        // MAX(numero)+1 sur une table qui grossit.
        Schema::create('piece_comptable_sequences', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_comptable_id')->constrained('journaux_comptables')->cascadeOnDelete();
            $table->foreignUlid('exercice_comptable_id')->constrained('exercices_comptables')->cascadeOnDelete();
            $table->unsignedInteger('dernier_numero')->default(0);

            $table->primary(['organization_id', 'journal_comptable_id', 'exercice_comptable_id'], 'piece_seq_primary');
        });

        // Contrainte d'équilibre ligne par ligne — best effort selon SGBD (MySQL 8.0.16+,
        // MariaDB 10.2+, SQLite l'appliquent ; ignorée silencieusement sinon, le service
        // applicatif reste la garde principale).
        try {
            DB::statement(
                'ALTER TABLE ecritures_comptables ADD CONSTRAINT chk_ecriture_debit_xor_credit '.
                'CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))'
            );
        } catch (Throwable $e) {
            // SGBD ne supportant pas CHECK : la validation applicative (EcritureComptableService)
            // reste la garde primaire, cf. #14 de la spec.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_comptable_sequences');
        Schema::dropIfExists('ecritures_comptables');
        Schema::dropIfExists('pieces_comptables');
        Schema::dropIfExists('compte_mappings');
        Schema::dropIfExists('tiers_comptables');
        Schema::dropIfExists('periodes_comptables');
        Schema::dropIfExists('exercices_comptables');
        Schema::dropIfExists('comptes_comptables');
        Schema::dropIfExists('journaux_comptables');
    }
};
