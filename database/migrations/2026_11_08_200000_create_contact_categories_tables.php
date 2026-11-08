<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 6a (ADR 0060) — catégories libres (ex. Partenaire) : étiquettes sans
 * effet métier, administrées par organisation et posées sur une fiche Personne ou Entreprise.
 * Code stable (jamais modifié après création), libellé renommable, désactivation sans suppression.
 * Deux tables de liaison à vraies clés étrangères plutôt qu'une table polymorphe : aucune entrée
 * ajoutée au morph map. Aucune ligne existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('libelle', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'contact_categories_org_code_unique');
            $table->index(['organization_id', 'is_active'], 'contact_categories_org_actif_idx');
        });

        Schema::create('personne_contact_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('personne_id')->constrained('personnes')->restrictOnDelete();
            $table->foreignUlid('contact_categorie_id')->constrained('contact_categories')->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['personne_id', 'contact_categorie_id'], 'personne_contact_categories_unique');
        });

        Schema::create('entreprise_contact_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('entreprise_tierce_id')->constrained('entreprises_tierces')->restrictOnDelete();
            $table->foreignUlid('contact_categorie_id')->constrained('contact_categories')->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entreprise_tierce_id', 'contact_categorie_id'], 'entreprise_contact_categories_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprise_contact_categories');
        Schema::dropIfExists('personne_contact_categories');
        Schema::dropIfExists('contact_categories');
    }
};
