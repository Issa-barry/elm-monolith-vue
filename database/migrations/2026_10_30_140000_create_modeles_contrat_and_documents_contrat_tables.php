<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrats de travail générés (docs/rh-contrat-pdf.md, ADR 0044) : un modèle de texte par type de
 * contrat et par organisation, et les versions PDF générées pour chaque contrat, avec l'instantané
 * des données utilisées. Nouvelles tables uniquement : aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modeles_contrat', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type_contrat', 10);
            $table->string('titre', 150);
            // Articles : [{titre, texte}], texte brut avec variables {{…}}.
            $table->json('articles');
            $table->string('statut', 20)->default('brouillon');
            // Incrémentée à chaque enregistrement : tracée dans chaque document généré.
            $table->unsignedInteger('revision')->default(1);
            $table->foreignUlid('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('active_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('active_le')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'type_contrat']);
        });

        Schema::create('documents_contrat', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contrat_id')->constrained('contrats')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignUlid('modele_contrat_id')->nullable()->constrained('modeles_contrat')->nullOnDelete();
            // Instantané figé : textes rendus et valeurs utilisées, jamais recalculés.
            $table->json('donnees');
            $table->string('fichier_path');
            $table->char('empreinte', 64);
            $table->string('statut', 20)->default('a_signer');
            $table->date('signe_le')->nullable();
            $table->foreignUlid('signature_enregistree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signature_enregistree_le')->nullable();
            $table->foreignUlid('genere_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contrat_id', 'version']);
            $table->index(['organization_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_contrat');
        Schema::dropIfExists('modeles_contrat');
    }
};
