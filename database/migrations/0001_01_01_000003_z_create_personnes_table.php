<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identité civile centrale d'une personne physique au sein d'une organisation — nom, prénom,
 * téléphone, email, localisation. Une même personne peut ensuite jouer plusieurs rôles
 * (User, Proprietaire, Employe, Livreur...) sans jamais dupliquer ces champs : chaque rôle
 * référence sa Personne via personne_id plutôt que de recopier son identité.
 *
 * telephone_normalise sert de clé métier anti-doublon PAR ORGANISATION (jamais globalement,
 * jamais de fusion automatique) : à la création d'un rôle, on cherche d'abord une Personne
 * existante avec ce téléphone dans l'organisation avant d'en créer une nouvelle — cf.
 * Personne::resoudreOuCreer(). Nullable : certains rôles (ex: livreur créé sans téléphone connu)
 * n'ont pas toujours cette information dès la création.
 *
 * organization_id nullable — même pattern que users.organization_id : l'auto-inscription
 * client "à la volée" (RegistrationService, app vitrine) crée un compte avant même de savoir à
 * quelle organisation il sera rattaché (cf. Client/Livreur/Proprietaire trouvés par téléphone
 * ensuite). L'unicité anti-doublon par organisation ne s'applique donc qu'aux Personne déjà
 * rattachées à une organisation — l'unicité globale du compte lui-même est garantie ailleurs,
 * par user_auth_identities.normalized_value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nom', 100)->nullable();
            $table->string('prenom', 100)->nullable();
            // Identité complète non structurée (saisie telle quelle) ou nom d'usage — utiles
            // quand nom/prénom séparés ne sont pas connus (ex: livreur identifié seulement par
            // "Baba Ousou") ou pour un surnom métier distinct de l'état civil. Priorité
            // d'affichage : cf. Personne::getNomCompletAttribute().
            $table->string('nom_complet', 150)->nullable();
            $table->string('surnom', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('telephone_normalise', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('code_pays', 2)->nullable();
            $table->string('code_phone_pays', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('adresse', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'telephone_normalise']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnes');
    }
};
