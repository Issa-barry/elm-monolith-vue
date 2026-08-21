<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identité d'une personne morale externe (fournisseur, prestataire...) — pendant de `personnes`
 * côté entreprise. Une même société peut ensuite jouer plusieurs rôles métier (Fournisseur,
 * Prestataire...) sans jamais dupliquer raison sociale/téléphone/email/adresse : chaque rôle
 * référence son EntrepriseTierce via entreprise_tierce_id plutôt que de recopier son identité.
 *
 * À ne pas confondre avec `organizations` (le tenant qui utilise ELM, ex. Eau La Maman) : chaque
 * EntrepriseTierce appartient à une organization_id — c'est une société EXTERNE que
 * l'organisation a comme fournisseur/prestataire, jamais l'organisation elle-même.
 *
 * telephone_normalise sert de clé métier anti-doublon PAR ORGANISATION, même principe que
 * personnes.telephone_normalise — cf. EntrepriseTierce::resoudreOuCreer().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprises_tierces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('raison_sociale', 200);
            $table->string('identifiant_fiscal', 50)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('telephone_normalise', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('code_pays', 2)->nullable();
            $table->string('code_phone_pays', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('adresse', 255)->nullable();
            // Interlocuteur humain actuel — optionnel et remplaçable sans affecter l'identité de
            // la société elle-même (cf. audit Contacts & Commission V2, addendum EntrepriseTierce).
            $table->foreignUlid('contact_personne_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'telephone_normalise']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprises_tierces');
    }
};
