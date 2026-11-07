<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 1 (ADR 0060) — sous-catégories d'une fonction (catégorie) et leurs
 * tables de liaison. `code` est stable : pour les sous-catégories système il reprend la valeur
 * déjà stockée en base (`clients.type`, `prestataires.type`), qui reste la seule lue par le code
 * métier jusqu'au lot 6. Les deux tables de liaison sont créées vides et ne sont lues nulle part
 * dans ce lot.
 *
 * Deux tables de liaison à clés étrangères réelles plutôt qu'une table polymorphe : aucune
 * entrée n'est ajoutée au morph map (compta_tiers stocke le nom de classe de ces fonctions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_sous_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('categorie', 30);
            $table->string('code', 50);
            $table->string('libelle', 100);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'categorie', 'code'], 'contact_sous_categories_org_categorie_code_unique');
            $table->index(['organization_id', 'categorie', 'is_active'], 'contact_sous_categories_org_categorie_actif_idx');
        });

        Schema::create('prestataire_sous_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('prestataire_id')->constrained('prestataires')->restrictOnDelete();
            $table->foreignUlid('contact_sous_categorie_id')->constrained('contact_sous_categories')->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['prestataire_id', 'contact_sous_categorie_id'], 'prestataire_sous_categories_unique');
        });

        Schema::create('fournisseur_sous_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('fournisseur_id')->constrained('fournisseurs')->restrictOnDelete();
            $table->foreignUlid('contact_sous_categorie_id')->constrained('contact_sous_categories')->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fournisseur_id', 'contact_sous_categorie_id'], 'fournisseur_sous_categories_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fournisseur_sous_categories');
        Schema::dropIfExists('prestataire_sous_categories');
        Schema::dropIfExists('contact_sous_categories');
    }
};
