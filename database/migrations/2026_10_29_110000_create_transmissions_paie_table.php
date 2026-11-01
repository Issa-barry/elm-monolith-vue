<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transmission mensuelle RH → Comptabilité, figée et versionnée (lot 4 RH,
 * docs/transmission-paie.md, ADR 0040). Nouvelles tables uniquement.
 *
 * Les lignes sont une copie : identifiants d'origine (contrat, affectation, saisie, type) gardés
 * pour la traçabilité, sans clé étrangère, pour qu'une version figée ne dépende jamais d'une donnée
 * vivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmissions_paie', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->unsignedTinyInteger('mois');
            $table->unsignedSmallInteger('version');
            $table->string('statut', 20)->default('brouillon');
            $table->text('motif')->nullable();
            $table->foreignUlid('version_precedente_id')->nullable()->constrained('transmissions_paie')->nullOnDelete();
            $table->json('anomalies')->nullable();
            $table->unsignedInteger('nb_salaries')->default(0);
            $table->decimal('total_verse', 15, 2)->default(0);
            $table->decimal('total_valorise', 15, 2)->default(0);
            $table->string('empreinte', 64)->nullable();
            $table->foreignUlid('preparee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('preparee_le')->nullable();
            $table->foreignUlid('validee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validee_le')->nullable();
            $table->foreignUlid('transmise_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transmise_le')->nullable();
            $table->foreignUlid('abandonnee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('abandonnee_le')->nullable();
            // Prise en charge par la Comptabilité (lot 5) : une version n'est reprise qu'une fois.
            $table->foreignUlid('prise_en_charge_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prise_en_charge_le')->nullable();
            $table->string('prise_en_charge_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'annee', 'mois', 'version'], 'transmission_paie_version_unique');
            $table->index(['organization_id', 'annee', 'mois', 'statut'], 'transmission_paie_mois_statut_idx');
        });

        Schema::create('transmission_paie_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('transmission_paie_id')->constrained('transmissions_paie')->cascadeOnDelete();
            $table->ulid('organization_id');
            $table->ulid('employe_id');
            $table->string('matricule', 50)->nullable();
            $table->string('nom_salarie', 200);
            $table->ulid('site_id')->nullable();
            $table->string('site_nom', 150)->nullable();
            $table->string('fonction_libelle', 150)->nullable();
            $table->ulid('contrat_id')->nullable();
            // contrat (salaire de base) ou affectation (prime, indemnité, allocation, avantage).
            $table->string('origine', 20);
            $table->ulid('affectation_id')->nullable();
            $table->ulid('saisie_id')->nullable();
            $table->ulid('type_remuneration_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->string('libelle', 150);
            $table->string('categorie', 30)->nullable();
            // verse : payé dans le net ; valorise : avantage en nature, hors net à payer.
            $table->string('versement', 20);
            $table->string('mode_calcul', 30)->nullable();
            $table->string('periodicite', 20)->nullable();
            $table->string('nature_montant', 20)->nullable();
            $table->boolean('proratisable')->default(false);
            $table->date('du');
            $table->date('au');
            $table->unsignedTinyInteger('jours');
            $table->unsignedTinyInteger('jours_mois');
            // Montant validé côté RH (salaire du contrat, montant attribué ou saisi), base et taux
            // d'un pourcentage, puis montant calculé du mois.
            $table->decimal('montant_reference', 15, 2)->nullable();
            $table->decimal('base_calcul', 15, 2)->nullable();
            $table->decimal('taux', 5, 2)->nullable();
            $table->decimal('montant', 15, 2);
            $table->string('detail_calcul', 255);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['transmission_paie_id', 'employe_id'], 'transmission_ligne_employe_idx');
            $table->index('affectation_id', 'transmission_ligne_affectation_idx');
            $table->index('saisie_id', 'transmission_ligne_saisie_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmission_paie_lignes');
        Schema::dropIfExists('transmissions_paie');
    }
};
