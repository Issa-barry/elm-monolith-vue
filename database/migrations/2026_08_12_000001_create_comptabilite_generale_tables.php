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
 * Tables préfixées `compta_` pour rester repérables d'un coup d'œil (liste de
 * tables, HeidiSQL, grep) au milieu du reste du schéma métier — c'est le seul
 * module du projet à porter un préfixe de domaine, réservé à la comptabilité
 * générale proprement dite (pas aux tables métier qui l'alimentent en amont,
 * cf. docs/data-dictionary-compta.md).
 *
 * Aucun numéro de compte SYSCOHADA n'est codé ici : les comptes/mappings sont
 * des données, pas du schéma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compta_comptes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 20);
            $table->string('libelle', 150);
            $table->foreignUlid('parent_id')->nullable()->constrained('compta_comptes')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'numero']);
        });

        Schema::create('compta_journaux', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10); // VE, AC, CA, BQ, MM, OD, ...
            $table->string('libelle', 100);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('compta_exercices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 50);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 20)->default('ouvert'); // ouvert | cloture
            $table->timestamp('cloture_at')->nullable();
            $table->foreignUlid('cloture_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'date_debut', 'date_fin'], 'compta_exercices_org_range_unique');
        });

        Schema::create('compta_periodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('exercice_comptable_id')->constrained('compta_exercices')->cascadeOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 20)->default('ouverte'); // ouverte | cloturee
            $table->timestamp('cloture_at')->nullable();
            $table->foreignUlid('cloture_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'date_debut', 'date_fin'], 'compta_periodes_org_range_unique');
            $table->index(['organization_id', 'statut']);
        });

        Schema::create('compta_tiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // proprietaire | livreur | salarie | client | fournisseur
            $table->string('tiersable_type');
            $table->ulid('tiersable_id');
            $table->foreignUlid('compte_collectif_id')->constrained('compta_comptes')->restrictOnDelete();
            $table->string('libelle', 150);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'tiersable_type', 'tiersable_id'], 'compta_tiers_org_tiersable_unique');
            $table->index(['organization_id', 'type']);
        });

        Schema::create('compta_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('evenement', 60); // ex: fiche_proprietaire_validee
            $table->string('role', 40); // ex: charge_commission | dette_tiers | avance_tiers | tresorerie
            $table->string('moyen_paiement', 30)->nullable(); // caisse | banque | mobile_money:orange | ... — null = défaut de l'événement
            $table->foreignUlid('compte_comptable_id')->constrained('compta_comptes')->restrictOnDelete();
            $table->foreignUlid('journal_comptable_id')->nullable()->constrained('compta_journaux')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'evenement', 'role', 'moyen_paiement'], 'compta_mapping_unique');
        });

        Schema::create('compta_pieces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_comptable_id')->constrained('compta_journaux')->restrictOnDelete();
            $table->foreignUlid('exercice_comptable_id')->constrained('compta_exercices')->restrictOnDelete();
            $table->foreignUlid('periode_comptable_id')->constrained('compta_periodes')->restrictOnDelete();
            $table->string('numero', 30);
            $table->date('date_piece');
            $table->string('libelle', 255);
            $table->string('source_type')->nullable();
            $table->ulid('source_id')->nullable();
            $table->string('type_evenement', 100);
            $table->string('statut', 20)->default('validee'); // validee | contrepassee
            $table->foreignUlid('piece_origine_id')->nullable()->constrained('compta_pieces')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'source_type', 'source_id', 'type_evenement'], 'compta_pieces_idempotency_unique');
            $table->unique(['organization_id', 'journal_comptable_id', 'exercice_comptable_id', 'numero'], 'compta_pieces_numero_unique');
            $table->index(['organization_id', 'periode_comptable_id']);
            $table->index(['organization_id', 'date_piece']);
        });

        Schema::create('compta_ecritures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('piece_comptable_id')->constrained('compta_pieces')->cascadeOnDelete();
            $table->foreignUlid('compte_comptable_id')->constrained('compta_comptes')->restrictOnDelete();
            $table->foreignUlid('tiers_comptable_id')->nullable()->constrained('compta_tiers')->nullOnDelete();
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
        // volontairement séparée de compta_pieces pour ne jamais dépendre d'un
        // MAX(numero)+1 sur une table qui grossit.
        Schema::create('compta_piece_sequences', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_comptable_id')->constrained('compta_journaux')->cascadeOnDelete();
            $table->foreignUlid('exercice_comptable_id')->constrained('compta_exercices')->cascadeOnDelete();
            $table->unsignedInteger('dernier_numero')->default(0);

            $table->primary(['organization_id', 'journal_comptable_id', 'exercice_comptable_id'], 'compta_piece_seq_primary');
        });

        // Contrainte d'équilibre ligne par ligne — best effort selon SGBD (MySQL 8.0.16+,
        // MariaDB 10.2+, SQLite l'appliquent ; ignorée silencieusement sinon, le service
        // applicatif reste la garde principale).
        try {
            DB::statement(
                'ALTER TABLE compta_ecritures ADD CONSTRAINT chk_compta_ecriture_debit_xor_credit '.
                'CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))'
            );
        } catch (Throwable $e) {
            // SGBD ne supportant pas CHECK : la validation applicative (EcritureComptableService)
            // reste la garde primaire, cf. #14 de la spec.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_piece_sequences');
        Schema::dropIfExists('compta_ecritures');
        Schema::dropIfExists('compta_pieces');
        Schema::dropIfExists('compta_mappings');
        Schema::dropIfExists('compta_tiers');
        Schema::dropIfExists('compta_periodes');
        Schema::dropIfExists('compta_exercices');
        Schema::dropIfExists('compta_comptes');
        Schema::dropIfExists('compta_journaux');
    }
};
